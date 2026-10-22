// LANE ONLY — never commit. node_modules is a symlink into the main checkout; let Vite serve its fonts.
import base from './vite.config'

export default async (env: unknown) => {
  const cfg = typeof base === 'function' ? await (base as (e: unknown) => unknown)(env) : base
  const c = cfg as { server?: { fs?: { allow?: string[] } } }
  c.server = { ...c.server, fs: { ...(c.server?.fs ?? {}), allow: [process.cwd(), '/Users/mohammedalharbimacbook/Developer/leadflow-crm/frontend'] } }

  return c
}
