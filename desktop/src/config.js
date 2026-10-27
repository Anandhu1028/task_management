const fs = require('node:fs');
const path = require('node:path');
const { app } = require('electron');

const PRODUCTION_API_BASE_URL = 'https://tracker-manage.focuzacademy.in/api';

function parseUrl(value) {
  try {
    return new URL(String(value || '').trim());
  } catch {
    return null;
  }
}

function isLocalHost(hostname) {
  return hostname === 'localhost' || hostname === '127.0.0.1' || hostname.endsWith('.test');
}

function assertApiBaseUrl(value, { packaged }) {
  const parsed = parseUrl(value);
  if (!parsed) {
    throw new Error('A valid TASK_API_BASE_URL is required.');
  }

  if (!['http:', 'https:'].includes(parsed.protocol)) {
    throw new Error('The API base URL must use http or https.');
  }

  if (packaged && parsed.protocol === 'http:' && !isLocalHost(parsed.hostname)) {
    throw new Error('The packaged desktop app requires HTTPS for non-local API URLs.');
  }

  const apiBaseUrl = parsed.toString().replace(/\/+$/, '');
  if (packaged && apiBaseUrl !== PRODUCTION_API_BASE_URL) {
    throw new Error(`The packaged desktop app must use ${PRODUCTION_API_BASE_URL}.`);
  }

  return apiBaseUrl;
}

function readJson(filePath) {
  if (!fs.existsSync(filePath)) {
    return null;
  }

  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function saveApiBaseUrl(value) {
  const apiBaseUrl = assertApiBaseUrl(value, { packaged: app.isPackaged });
  if (app.isPackaged) {
    return apiBaseUrl;
  }

  const configPath = path.join(app.getPath('userData'), 'api-config.json');
  fs.mkdirSync(path.dirname(configPath), { recursive: true });
  fs.writeFileSync(configPath, JSON.stringify({ apiBaseUrl, userConfigured: true }, null, 2), 'utf8');

  return apiBaseUrl;
}

function resolveApiBaseUrl() {
  const packaged = app.isPackaged;
  const packagedConfig = packaged
    ? readJson(path.join(process.resourcesPath, 'api-config.json'))
    : readJson(path.join(__dirname, '..', 'resources', 'api-config.json'));

  if (packaged) {
    if (!packagedConfig?.apiBaseUrl) {
      throw new Error(`The packaged API configuration is missing. Reinstall the app with ${PRODUCTION_API_BASE_URL}.`);
    }

    return assertApiBaseUrl(packagedConfig.apiBaseUrl, { packaged: true });
  }

  const userConfig = readJson(path.join(app.getPath('userData'), 'api-config.json'));
  const userApiBaseUrl = userConfig?.apiBaseUrl;
  const envUrl = process.env.TASK_API_BASE_URL;
  const configured = userApiBaseUrl || envUrl || packagedConfig?.apiBaseUrl;

  return assertApiBaseUrl(configured, { packaged: false });
}

module.exports = {
  resolveApiBaseUrl,
  assertApiBaseUrl,
  saveApiBaseUrl,
  PRODUCTION_API_BASE_URL,
};
