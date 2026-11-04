/**
 * src/client/src/services/api.ts
 * Client HTTP per la comunicazione con il backend API REST:
 * - Gestione automatica credenziali sessione (credentials: 'include')
 * - Fetch e iniezione trasparente dell'header X-CSRF-Token su richieste mutative
 * - Rilevamento sessioni scadute (401) con reindirizzamento al login
 * - Supporto per download file binari (blob)
 */

let cachedCsrfToken: string | null = null;

export class ApiError extends Error {
  status: number;
  data: any;

  constructor(message: string, status: number, data?: any) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.data = data;
  }
}

/**
 * Recupera un nuovo token CSRF dall'endpoint dedicato
 */
export async function fetchCsrfToken(): Promise<string> {
  try {
    const res = await fetch('/api/v1/auth/csrf-token', { credentials: 'include' });
    if (res.ok) {
      const body = await res.json();
      const token: string = body.csrfToken || '';
      cachedCsrfToken = token;
      return token;
    }
  } catch (err) {
    console.warn('Impossibile recuperare CSRF token:', err);
  }
  return '';
}

/**
 * Esegue una richiesta HTTP con credenziali e CSRF inclusi
 */
export async function apiRequest<T = any>(
  endpoint: string,
  options: RequestInit = {},
  isRetry = false
): Promise<T> {
  const method = (options.method || 'GET').toUpperCase();
  const headers: Record<string, string> = {
    ...(options.headers as Record<string, string>)
  };

  // Iniezione header CSRF per metodi mutativi
  if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
    if (!cachedCsrfToken) {
      await fetchCsrfToken();
    }
    if (cachedCsrfToken) {
      headers['X-CSRF-Token'] = cachedCsrfToken;
    }
  }

  // Content-Type predefinito per payload JSON
  if (options.body && typeof options.body === 'string' && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(endpoint, {
    ...options,
    headers,
    credentials: 'include'
  });

  // Gestione sessione scaduta (401 Unauthorized)
  if (response.status === 401) {
    window.dispatchEvent(new CustomEvent('auth:unauthorized'));
    cachedCsrfToken = null;
    throw new ApiError('Sessione scaduta o non valida', 401);
  }

  // Self-Healing CSRF: su 403 per metodi mutativi, svuota cache token, ricarica e riprova una volta
  if (response.status === 403 && !isRetry && ['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
    cachedCsrfToken = null;
    const freshToken = await fetchCsrfToken();
    if (freshToken) {
      const retryHeaders = {
        ...headers,
        'X-CSRF-Token': freshToken
      };
      return apiRequest<T>(endpoint, { ...options, headers: retryHeaders }, true);
    }
  }

  // Risposta senza contenuto (es. 204 No Content)
  if (response.status === 204) {
    return {} as T;
  }

  let responseData: any = null;
  const contentType = response.headers.get('content-type');
  if (contentType && contentType.includes('application/json')) {
    responseData = await response.json();
  } else {
    responseData = await response.text();
  }

  if (!response.ok) {
    const errorMessage =
      (responseData && typeof responseData === 'object' && (responseData.error || responseData.message)) ||
      `Errore richiesta (HTTP ${response.status})`;
    throw new ApiError(errorMessage, response.status, responseData);
  }

  return responseData as T;
}

/**
 * Download binario di un file (es. allegato email) con gestione del nome file
 */
export async function downloadBlob(url: string, fallbackFilename: string): Promise<void> {
  const response = await fetch(url, { credentials: 'include' });
  if (response.status === 401) {
    window.dispatchEvent(new CustomEvent('auth:unauthorized'));
    throw new ApiError('Sessione scaduta', 401);
  }
  if (!response.ok) {
    throw new ApiError('Impossibile scaricare il file richiesto', response.status);
  }

  // Estrazione nome file da Content-Disposition se presente
  let filename = fallbackFilename;
  const disposition = response.headers.get('content-disposition');
  if (disposition) {
    const matchUtf8 = disposition.match(/filename\*=UTF-8''([^;]+)/i);
    if (matchUtf8 && matchUtf8[1]) {
      filename = decodeURIComponent(matchUtf8[1]);
    } else {
      const matchPlain = disposition.match(/filename="?([^";]+)"?/i);
      if (matchPlain && matchPlain[1]) {
        filename = matchPlain[1];
      }
    }
  }

  const blob = await response.blob();
  const blobUrl = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = blobUrl;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.URL.revokeObjectURL(blobUrl);
}

/**
 * Avvia il download nativo nel browser senza caricare il file nella memoria JavaScript (anti-OOM)
 */
export function triggerDirectDownload(url: string, filename?: string): void {
  const a = document.createElement('a');
  a.href = url;
  if (filename) {
    a.download = filename;
  }
  a.style.display = 'none';
  document.body.appendChild(a);
  a.click();
  setTimeout(() => a.remove(), 1000);
}

export const api = {
  get: <T = any>(url: string) => apiRequest<T>(url, { method: 'GET' }),
  post: <T = any>(url: string, body?: any) =>
    apiRequest<T>(url, {
      method: 'POST',
      body: body !== undefined ? (typeof body === 'string' ? body : JSON.stringify(body)) : undefined
    }),
  put: <T = any>(url: string, body?: any) =>
    apiRequest<T>(url, {
      method: 'PUT',
      body: body !== undefined ? (typeof body === 'string' ? body : JSON.stringify(body)) : undefined
    }),
  delete: <T = any>(url: string) => apiRequest<T>(url, { method: 'DELETE' }),
  download: downloadBlob,
  directDownload: triggerDirectDownload
};
