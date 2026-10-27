const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const Module = require('node:module');
const { after, before, test } = require('node:test');

const productionApiBaseUrl = 'https://tracker-manage.focuzacademy.in/api';
const testDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'task-management-desktop-config-'));
const resourcesDirectory = path.join(testDirectory, 'resources');
const userDataDirectory = path.join(testDirectory, 'user-data');
let packaged = false;
let originalTaskApiBaseUrl;
let originalElectronLoad;
let originalResourcesPath;

const electronApp = {
  get isPackaged() {
    return packaged;
  },
  getPath(name) {
    assert.equal(name, 'userData');
    return userDataDirectory;
  },
};

before(() => {
  originalElectronLoad = Module._load;
  Module._load = function loadWithElectronMock(request, parent, isMain) {
    if (request === 'electron') return { app: electronApp };
    return originalElectronLoad.call(this, request, parent, isMain);
  };

  originalResourcesPath = Object.getOwnPropertyDescriptor(process, 'resourcesPath');
  Object.defineProperty(process, 'resourcesPath', {
    configurable: true,
    value: resourcesDirectory,
  });

  fs.mkdirSync(resourcesDirectory, { recursive: true });
  fs.mkdirSync(userDataDirectory, { recursive: true });
  fs.writeFileSync(
    path.join(resourcesDirectory, 'api-config.json'),
    JSON.stringify({ apiBaseUrl: productionApiBaseUrl }),
  );
  originalTaskApiBaseUrl = process.env.TASK_API_BASE_URL;
});

after(() => {
  Module._load = originalElectronLoad;
  if (originalResourcesPath) {
    Object.defineProperty(process, 'resourcesPath', originalResourcesPath);
  } else {
    delete process.resourcesPath;
  }
  if (originalTaskApiBaseUrl === undefined) delete process.env.TASK_API_BASE_URL;
  else process.env.TASK_API_BASE_URL = originalTaskApiBaseUrl;
  fs.rmSync(testDirectory, { recursive: true, force: true });
});

test('packaged builds ignore stale local and user-configured API URLs', () => {
  packaged = true;
  process.env.TASK_API_BASE_URL = 'http://localhost:8000/api';
  fs.writeFileSync(
    path.join(userDataDirectory, 'api-config.json'),
    JSON.stringify({ apiBaseUrl: 'http://127.0.0.1:8000/api', userConfigured: true }),
  );

  const { resolveApiBaseUrl } = require('../src/config');
  assert.equal(resolveApiBaseUrl(), productionApiBaseUrl);
});

test('packaged builds reject API URL changes away from production', () => {
  packaged = true;
  const { assertApiBaseUrl, saveApiBaseUrl } = require('../src/config');

  assert.throws(
    () => assertApiBaseUrl('http://127.0.0.1:8000/api', { packaged: true }),
    /must use https:\/\/tracker-manage\.focuzacademy\.in\/api/,
  );
  assert.throws(
    () => saveApiBaseUrl('https://other.example/api'),
    /must use https:\/\/tracker-manage\.focuzacademy\.in\/api/,
  );
  assert.equal(saveApiBaseUrl(`${productionApiBaseUrl}/`), productionApiBaseUrl);
});

test('development builds continue to honor explicit local API configuration', () => {
  packaged = false;
  process.env.TASK_API_BASE_URL = 'http://127.0.0.1:8000/api';

  const { resolveApiBaseUrl } = require('../src/config');
  assert.equal(resolveApiBaseUrl(), 'http://127.0.0.1:8000/api');
});
