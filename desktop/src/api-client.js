const { net } = require('electron');
const { resolveApiBaseUrl } = require('./config');

const ALLOWED_METHODS = new Set(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);

function joinUrl(base, pathname) {
  return `${base}${pathname.startsWith('/') ? pathname : `/${pathname}`}`;
}

function request({ method, path, token, body, query }) {
  const apiBaseUrl = resolveApiBaseUrl();
  const verb = String(method || 'GET').toUpperCase();
  if (!ALLOWED_METHODS.has(verb)) {
    return Promise.reject(new Error('Unsupported request method.'));
  }

  const url = new URL(joinUrl(apiBaseUrl, path));
  if (query && typeof query === 'object') {
    Object.entries(query).forEach(([key, value]) => {
      if (value !== undefined && value !== null && String(value) !== '') {
        url.searchParams.set(key, String(value));
      }
    });
  }

  return new Promise((resolve, reject) => {
    const httpRequest = net.request({
      method: verb,
      url: url.toString(),
    });

    httpRequest.setHeader('Accept', 'application/json');
    httpRequest.setHeader('Content-Type', 'application/json');
    if (token) {
      httpRequest.setHeader('Authorization', `Bearer ${token}`);
    }

    let raw = '';
    httpRequest.on('response', (response) => {
      response.on('data', (chunk) => {
        raw += chunk.toString();
      });
      response.on('end', () => {
        if (raw === '') {
          resolve({
            ok: false,
            status: response.statusCode,
            data: { message: 'The server returned an empty response.' },
          });

          return;
        }

        let payload = {};
        try {
          payload = JSON.parse(raw);
        } catch {
          resolve({
            ok: false,
            status: response.statusCode,
            data: { message: 'The server returned an unexpected response.' },
          });

          return;
        }

        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
          resolve({
            ok: false,
            status: response.statusCode,
            data: { message: 'The server returned an unexpected response.' },
          });

          return;
        }

        resolve({
          ok: response.statusCode >= 200 && response.statusCode < 300,
          status: response.statusCode,
          data: payload,
        });
      });
    });

    httpRequest.on('error', (error) => {
      reject(error);
    });

    if (body && verb !== 'GET') {
      httpRequest.write(JSON.stringify(body));
    }

    httpRequest.end();
  });
}

module.exports = {
  request,
  resolveApiBaseUrl,
};
