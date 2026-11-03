import { create } from 'zustand'

const KEY = 'leadflow.project'

function read(): string | null {
  try {
    return localStorage.getItem(KEY)
  } catch {
    return null
  }
}

interface ProjectState {
  /** The project the person chose to look into, or null for everything they may see. */
  selected: string | null
  select: (id: string | null) => void
}

/** A way of looking, kept on the device. The server only ever narrows with it, inside what is already permitted. */
export const useProject = create<ProjectState>((set) => ({
  selected: read(),
  select: (id) => {
    try {
      if (id) localStorage.setItem(KEY, id)
      else localStorage.removeItem(KEY)
    } catch {
      /* private browsing — the choice simply will not survive a reload */
    }
    set({ selected: id })
  },
}))
