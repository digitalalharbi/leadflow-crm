import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

/** Merge Tailwind classes so a caller's class always wins over a component default. */
export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}
