import { create } from 'zustand'

const KEY = 'leadflow.company'

function read(): string | null {
  try {
    return localStorage.getItem(KEY)
  } catch {
    return null
  }
}

interface CompanyState {
  /** The company the person chose to look into, or null for the whole holding. */
  selected: string | null
  select: (id: string | null) => void
}

/**
 * Which company the screens are scoped to.
 *
 * Kept on the device, not on the account: it is a way of looking, not a fact
 * about the person, and two tabs on two companies is a reasonable thing to do.
 * The server decides what is *permitted*; this only ever narrows inside that.
 */
export const useCompany = create<CompanyState>((set) => ({
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
