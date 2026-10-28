const fs = require('node:fs');
const path = require('node:path');
const { app, BrowserWindow, ipcMain, shell } = require('electron');
const { request } = require('./api-client');
const { saveToken, readToken, clearToken } = require('./auth-store');
const { resolveApiBaseUrl, saveApiBaseUrl } = require('./config');

if (!app.isPackaged) {
  require('dotenv').config({ path: path.join(__dirname, '..', '.env') });
}

const TASK_ACTIONS = new Set([
  'accept',
  'start',
  'work-updates',
  'resume',
  'status',
  'risk',
  'review',
]);

let mainWindow;
let rendererReloadTimeout;

function isPlainObject(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1280,
    height: 840,
    minWidth: 960,
    minHeight: 640,
    show: false,
    autoHideMenuBar: true,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      spellcheck: false,
    },
  });

  mainWindow.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
  mainWindow.webContents.on('will-navigate', (event, url) => {
    const allowed = url.startsWith('file://');
    if (!allowed) {
      event.preventDefault();
    }
  });

  mainWindow.loadFile(path.join(__dirname, '..', 'renderer', 'index.html'));
  mainWindow.once('ready-to-show', () => mainWindow.show());

  if (!app.isPackaged) {
    const rendererDirectory = path.join(__dirname, '..', 'renderer');
    const watchedFiles = new Set(['app.js', 'index.html', 'styles.css']);
    const rendererWatcher = fs.watch(rendererDirectory, (_eventType, filename) => {
      if (!watchedFiles.has(filename?.toString())) {
        return;
      }

      clearTimeout(rendererReloadTimeout);
      rendererReloadTimeout = setTimeout(() => {
        if (mainWindow && !mainWindow.isDestroyed()) {
          mainWindow.webContents.reloadIgnoringCache();
        }
      }, 150);
    });

    mainWindow.once('closed', () => {
      rendererWatcher.close();
      clearTimeout(rendererReloadTimeout);
      rendererReloadTimeout = undefined;
      mainWindow = null;
    });
  }
}

async function authorizedRequest(options) {
  const token = readToken();
  if (!token) {
    return { ok: false, status: 401, data: { message: 'You are not signed in.' } };
  }

  const response = await request({ ...options, token });
  if (response.status === 401) {
    clearToken();
  }

  return response;
}

ipcMain.handle('desktop:session', async () => {
  const token = readToken();
  if (!token) {
    return { authenticated: false };
  }

  const response = await request({ method: 'GET', path: '/auth/user', token });
  if (!response.ok) {
    if (response.status === 401 || response.status === 403) {
      clearToken();
    }

    return { authenticated: false, error: response.data?.message || 'Session expired.' };
  }

  if (!isPlainObject(response.data?.user)) {
    clearToken();
    return { authenticated: false, error: 'Laravel returned invalid session data. Please sign in again.' };
  }

  return { authenticated: true, user: response.data.user };
});

ipcMain.handle('desktop:get-api-url', () => {
  try {
    return { ok: true, apiBaseUrl: resolveApiBaseUrl() };
  } catch (error) {
    return { ok: false, message: error.message };
  }
});

ipcMain.handle('desktop:set-api-url', (_event, value) => {
  if (typeof value !== 'string') {
    return { ok: false, message: 'Enter a valid API base URL.' };
  }

  try {
    clearToken();
    return { ok: true, apiBaseUrl: saveApiBaseUrl(value) };
  } catch (error) {
    return { ok: false, message: error.message };
  }
});

ipcMain.handle('desktop:login', async (_event, payload) => {
  if (!isPlainObject(payload) || typeof payload.email !== 'string' || typeof payload.password !== 'string') {
    return { ok: false, status: 422, data: { message: 'Email and password are required.' } };
  }

  const response = await request({
    method: 'POST',
    path: '/auth/login',
    body: {
      email: payload.email.trim(),
      password: payload.password,
    },
  });

  if (response.ok
    && typeof response.data?.token === 'string'
    && response.data.token.trim() !== ''
    && isPlainObject(response.data.user)) {
    saveToken(response.data.token);
    return { ok: true, status: response.status, data: { user: response.data.user } };
  }

  if (response.ok) {
    return {
      ok: false,
      status: response.status,
      data: { message: 'Laravel returned an invalid sign-in response.' },
    };
  }

  return response;
});

ipcMain.handle('desktop:logout', async () => {
  const token = readToken();
  let warning = null;
  if (token) {
    try {
      const response = await request({ method: 'POST', path: '/auth/logout', token });
      if (!response.ok && response.status !== 401) {
        warning = 'Your local session was cleared, but the server did not confirm token revocation.';
      }
    } catch {
      warning = 'Your local session was cleared, but the server could not be reached to revoke the token.';
    }
  }

  clearToken();

  return { ok: true, warning };
});

ipcMain.handle('desktop:meta', async () => authorizedRequest({ method: 'GET', path: '/desktop/meta' }));

ipcMain.handle('desktop:list-tasks', async (_event, query) => {
  if (query !== undefined && !isPlainObject(query)) {
    return { ok: false, status: 422, data: { message: 'Invalid task filters.' } };
  }

  return authorizedRequest({ method: 'GET', path: '/tasks', query });
});

ipcMain.handle('desktop:get-task', async (_event, taskId) => {
  const id = Number(taskId);
  if (!Number.isInteger(id) || id < 1) {
    return { ok: false, status: 422, data: { message: 'A valid task id is required.' } };
  }

  return authorizedRequest({ method: 'GET', path: `/tasks/${id}` });
});

ipcMain.handle('desktop:save-task', async (_event, payload) => {
  if (!isPlainObject(payload)) {
    return { ok: false, status: 422, data: { message: 'Invalid task payload.' } };
  }

  const { id, ...body } = payload;
  if (id) {
    const taskId = Number(id);
    if (!Number.isInteger(taskId) || taskId < 1) {
      return { ok: false, status: 422, data: { message: 'A valid task id is required.' } };
    }

    return authorizedRequest({ method: 'PUT', path: `/tasks/${taskId}`, body });
  }

  return authorizedRequest({ method: 'POST', path: '/tasks', body });
});

ipcMain.handle('desktop:task-action', async (_event, payload) => {
  if (!isPlainObject(payload) || !TASK_ACTIONS.has(payload.action)) {
    return { ok: false, status: 422, data: { message: 'Unsupported task action.' } };
  }

  const taskId = Number(payload.taskId);
  if (!Number.isInteger(taskId) || taskId < 1) {
    return { ok: false, status: 422, data: { message: 'A valid task id is required.' } };
  }

  const body = isPlainObject(payload.body) ? payload.body : {};

  return authorizedRequest({
    method: 'POST',
    path: `/tasks/${taskId}/${payload.action}`,
    body,
  });
});

ipcMain.handle('desktop:save-subtask', async (_event, payload) => {
  if (!isPlainObject(payload)) {
    return { ok: false, status: 422, data: { message: 'Invalid subtask payload.' } };
  }

  const taskId = Number(payload.taskId);
  if (!Number.isInteger(taskId) || taskId < 1) {
    return { ok: false, status: 422, data: { message: 'A valid task id is required.' } };
  }

  const body = isPlainObject(payload.body) ? payload.body : {};
  if (payload.subtaskId) {
    const subtaskId = Number(payload.subtaskId);
    if (!Number.isInteger(subtaskId) || subtaskId < 1) {
      return { ok: false, status: 422, data: { message: 'A valid subtask id is required.' } };
    }

    return authorizedRequest({
      method: 'PATCH',
      path: `/tasks/${taskId}/subtasks/${subtaskId}`,
      body,
    });
  }

  return authorizedRequest({
    method: 'POST',
    path: `/tasks/${taskId}/subtasks`,
    body,
  });
});

ipcMain.handle('desktop:toggle-subtask', async (_event, payload) => {
  const taskId = Number(payload?.taskId);
  const subtaskId = Number(payload?.subtaskId);
  if (!Number.isInteger(taskId) || !Number.isInteger(subtaskId) || taskId < 1 || subtaskId < 1) {
    return { ok: false, status: 422, data: { message: 'A valid subtask is required.' } };
  }

  return authorizedRequest({
    method: 'PATCH',
    path: `/tasks/${taskId}/subtasks/${subtaskId}/toggle`,
    body: {},
  });
});

ipcMain.handle('desktop:request-deadline-extension', async (_event, payload) => {
  const taskId = Number(payload?.taskId);
  if (!Number.isInteger(taskId) || taskId < 1 || !isPlainObject(payload?.body)) {
    return { ok: false, status: 422, data: { message: 'A valid deadline extension request is required.' } };
  }

  return authorizedRequest({
    method: 'POST',
    path: `/tasks/${taskId}/deadline-extension-requests`,
    body: payload.body,
  });
});

ipcMain.handle('desktop:review-deadline-extension', async (_event, payload) => {
  const taskId = Number(payload?.taskId);
  const requestId = Number(payload?.requestId);
  if (!Number.isInteger(taskId) || taskId < 1
    || !Number.isInteger(requestId) || requestId < 1
    || !isPlainObject(payload?.body)) {
    return { ok: false, status: 422, data: { message: 'A valid deadline extension review is required.' } };
  }

  return authorizedRequest({
    method: 'POST',
    path: `/tasks/${taskId}/deadline-extension-requests/${requestId}/review`,
    body: payload.body,
  });
});

app.whenReady().then(() => {
  createWindow();

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createWindow();
    }
  });
});

app.on('web-contents-created', (_event, contents) => {
  contents.setWindowOpenHandler(({ url }) => {
    try {
      const parsedUrl = new URL(url);
      if (parsedUrl.protocol === 'https:') {
        shell.openExternal(url);
      }
    } catch {
      // Invalid external URLs are denied.
    }

    return { action: 'deny' };
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});
