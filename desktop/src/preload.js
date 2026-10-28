const { contextBridge, ipcRenderer } = require('electron');

const ALLOWED_CHANNELS = new Set([
  'desktop:session',
  'desktop:get-api-url',
  'desktop:set-api-url',
  'desktop:login',
  'desktop:logout',
  'desktop:meta',
  'desktop:list-tasks',
  'desktop:get-task',
  'desktop:save-task',
  'desktop:task-action',
  'desktop:save-subtask',
  'desktop:toggle-subtask',
  'desktop:request-deadline-extension',
  'desktop:review-deadline-extension',
]);

function invoke(channel, payload) {
  if (!ALLOWED_CHANNELS.has(channel)) {
    return Promise.reject(new Error('Blocked IPC channel.'));
  }

  return ipcRenderer.invoke(channel, payload);
}

contextBridge.exposeInMainWorld('desktopApi', {
  getSession: () => invoke('desktop:session'),
  getApiUrl: () => invoke('desktop:get-api-url'),
  setApiUrl: (apiBaseUrl) => invoke('desktop:set-api-url', apiBaseUrl),
  login: (email, password) => invoke('desktop:login', { email, password }),
  logout: () => invoke('desktop:logout'),
  getMeta: () => invoke('desktop:meta'),
  listTasks: (query) => invoke('desktop:list-tasks', query),
  getTask: (taskId) => invoke('desktop:get-task', taskId),
  saveTask: (payload) => invoke('desktop:save-task', payload),
  taskAction: (payload) => invoke('desktop:task-action', payload),
  saveSubtask: (payload) => invoke('desktop:save-subtask', payload),
  toggleSubtask: (payload) => invoke('desktop:toggle-subtask', payload),
  requestDeadlineExtension: (payload) => invoke('desktop:request-deadline-extension', payload),
  reviewDeadlineExtension: (payload) => invoke('desktop:review-deadline-extension', payload),
});
