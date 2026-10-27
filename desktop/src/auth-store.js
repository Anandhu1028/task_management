const fs = require('node:fs');
const path = require('node:path');
const { app, safeStorage } = require('electron');

function tokenPath() {
  return path.join(app.getPath('userData'), 'desktop-auth.bin');
}

function saveToken(token) {
  if (typeof token !== 'string' || token.trim() === '') {
    throw new Error('A valid authentication token is required.');
  }

  if (!safeStorage.isEncryptionAvailable()) {
    throw new Error('Secure token storage is not available on this system.');
  }

  fs.writeFileSync(tokenPath(), safeStorage.encryptString(token.trim()));
}

function readToken() {
  const file = tokenPath();
  if (!fs.existsSync(file)) {
    return null;
  }

  if (!safeStorage.isEncryptionAvailable()) {
    return null;
  }

  try {
    return safeStorage.decryptString(fs.readFileSync(file));
  } catch {
    return null;
  }
}

function clearToken() {
  const file = tokenPath();
  if (fs.existsSync(file)) {
    fs.unlinkSync(file);
  }
}

module.exports = {
  saveToken,
  readToken,
  clearToken,
};
