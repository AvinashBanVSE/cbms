export class PortalRequestError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message);
  }
}

type RequestValue =
  | string
  | number
  | boolean
  | null
  | RequestValue[]
  | { [key: string]: RequestValue };

export async function portalRequest(
  path: string,
  body?: Record<string, RequestValue>,
  setupCode?: string,
): Promise<Response> {
  const headers: Record<string, string> = { Accept: "application/json" };
  if (body) {
    const csrf = await fetch("/api/v1/csrf", { headers, cache: "no-store" });
    if (!csrf.ok) throw new Error("Session unavailable. Please retry.");
    const token = (await csrf.json())?.token;
    if (typeof token !== "string" || !token.trim())
      throw new Error("Session unavailable. Please retry.");
    headers["X-CSRF-TOKEN"] = token;
    headers["Content-Type"] = "application/json";
  }
  if (setupCode) headers["X-Setup-Code"] = setupCode;
  const response = await fetch("/api/v1/" + path, {
    method: body ? "POST" : "GET",
    headers,
    cache: "no-store",
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    const messages = Object.values(payload?.errors ?? {}).flat();
    const descriptions: Record<number, string> = {
      401: "Sign in with your user code, email and password.",
      403: "Access denied. Check your permission or setup code.",
      409: path.startsWith("installation/")
        ? "The installation stage has changed. Reload this page."
        : path.startsWith("provider-migrations/")
          ? "The migration scope changed or requires correction. Preview it again."
          : path.startsWith("businesses/") && path.endsWith("/lifecycle")
            ? "The business state changed, or the requested transition is unavailable."
            : path.endsWith("/lifecycle")
              ? "The account state changed or this transition is unavailable. Load the account state again."
              : /organization-users|organization-setup/.test(path)
                ? "Account setup is unavailable in its current state. Check the Organization policy or return to sign in."
                : "This name is already registered in this context.",
      419: "Your session has expired. Reload this page and try again.",
      429: "Too many attempts. Please try again later.",
    };
    throw new PortalRequestError(
      messages.length
        ? messages.join(" ")
        : (descriptions[response.status] ?? "Request failed. Please retry."),
      response.status,
    );
  }
  return response;
}
