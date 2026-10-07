/**
 * Get the path of the folder on folders route
 * This function does not check if this is the folder route
 */
export function getFolderRoutePath(basePath: string) {
  let path = (_m.route.params.path || '/') as string | string[];
  path = typeof path === 'string' ? path : path.join('/');
  path = `${basePath}/${path}`;
  path = path.replaceAll(/\/\/+/g, '/'); // Remove double slashes
  return path;
}

/** Normalize a route param to string (repeatable params parse as string[]). */
export function routeParamToString(param?: string | string[]): string {
  if (Array.isArray(param)) return param.join('/');
  return param?.toString() ?? String();
}
