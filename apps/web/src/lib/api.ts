const TOKEN_KEY = "vtt_token";
let writeRevision = 0;
let pendingWrites = 0;

export function apiWriteState() {
  return { revision: writeRevision, busy: pendingWrites > 0 };
}

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string | null) {
  if (token) localStorage.setItem(TOKEN_KEY, token);
  else localStorage.removeItem(TOKEN_KEY);
}

export class ApiError extends Error {
  status: number;
  constructor(status: number, message: string) {
    super(message);
    this.status = status;
  }
}

export async function api<T>(
  path: string,
  options: RequestInit & { formData?: FormData } = {},
): Promise<T> {
  const headers = new Headers(options.headers);
  const token = getToken();
  if (token) headers.set("Authorization", `Bearer ${token}`);
  headers.set("Accept", "application/json");
  if (options.body && !options.formData) headers.set("Content-Type", "application/json");

  const mutating = options.method !== undefined && options.method.toUpperCase() !== "GET";
  if (mutating) {
    writeRevision++;
    pendingWrites++;
  }
  try {
    const res = await fetch(`/api${path}`, {
      ...options,
      signal: options.signal
        ? AbortSignal.any([options.signal, AbortSignal.timeout(30000)])
        : AbortSignal.timeout(30000),
      headers,
      body: options.formData ?? options.body,
    });

    if (!res.ok) {
      if (res.status === 401 && token === getToken())
        window.dispatchEvent(new Event("vtt:unauthorized"));
      let message = res.statusText;
      try {
        const data = await res.json();
        message =
          data.message ||
          Object.values(data.errors ?? {})
            .flat()
            .join(" ") ||
          message;
      } catch {
        /* ignore */
      }
      throw new ApiError(res.status, message);
    }

    if (res.status === 204) return undefined as T;
    return (await res.json()) as T;
  } catch (error) {
    if (error instanceof Error && error.name === "TimeoutError")
      throw new Error("O servidor demorou para responder. Verifique a conexão e tente novamente.");
    throw error;
  } finally {
    if (mutating) {
      writeRevision++;
      pendingWrites--;
    }
  }
}
