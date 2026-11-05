/**
 * Laravel expone el token CSRF vigente en la cookie XSRF-TOKEN.
 * Tras un inicio de sesión mediante Inertia, el <meta> inicial puede estar
 * desactualizado; por eso no se utiliza como fuente de autenticación.
 *
 * El valor se envía en X-XSRF-TOKEN, no en X-CSRF-TOKEN: el primero
 * permite a Laravel descifrar la cookie y cotejarla con la sesión.
 */
export function xsrfTokenFromCookie(cookieHeader: string): string | null {
  for (const entry of cookieHeader.split(';')) {
    const separator = entry.indexOf('=');
    if (separator < 0 || entry.slice(0, separator).trim() !== 'XSRF-TOKEN') continue;
    const encoded = entry.slice(separator + 1).trim();
    if (!encoded) return null;
    try {
      return decodeURIComponent(encoded);
    } catch {
      return null;
    }
  }
  return null;
}

export function csrfHeaders(cookieHeader: string): Record<string, string> {
  const token = xsrfTokenFromCookie(cookieHeader);
  return token ? { 'X-XSRF-TOKEN': token } : {};
}
