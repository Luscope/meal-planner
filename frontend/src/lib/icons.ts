/**
 * Inline stroke-icon set for the "Essbar" redesign — 24×24 viewBox, drawn
 * with `currentColor` so each usage site controls its own color via CSS.
 * Ported from the design canvas's SVG markup (no icon-library dependency).
 */
export type IconName =
  | 'calendar'
  | 'book'
  | 'basket'
  | 'home'
  | 'logout'
  | 'list'
  | 'chevron-left'
  | 'chevron-right'
  | 'chevron-down'
  | 'plus'
  | 'auto-plan'
  | 'close'
  | 'thumbs-up'
  | 'thumbs-down'
  | 'search'
  | 'clock'
  | 'flame'
  | 'users'
  | 'heart'
  | 'minus'
  | 'copy'
  | 'check'
  | 'share'
  | 'import'

export const ICON_PATHS: Record<IconName, string> = {
  calendar:
    '<rect x="3.5" y="5" width="17" height="15.5" rx="3"></rect><path d="M3.5 10h17M8 3v4M16 3v4"></path>',
  book: '<path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3z"></path><path d="M5 17a3 3 0 0 1 3-3h11"></path>',
  basket:
    '<circle cx="9" cy="20" r="1.3"></circle><circle cx="18" cy="20" r="1.3"></circle><path d="M3 4h2.5l2.2 11h11.1l2-8H6.5"></path>',
  home: '<path d="M4 11 12 4l8 7"></path><path d="M6 9.5V20h12V9.5"></path><path d="M10 20v-5h4v5"></path>',
  logout:
    '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"></path><path d="M10 8l-4 4 4 4M6 12h10"></path>',
  list: '<path d="M9 6h11M9 12h11M9 18h11"></path><circle cx="4.5" cy="6" r="1"></circle><circle cx="4.5" cy="12" r="1"></circle><circle cx="4.5" cy="18" r="1"></circle>',
  'chevron-left': '<path d="m15 5-7 7 7 7"></path>',
  'chevron-right': '<path d="m9 5 7 7-7 7"></path>',
  'chevron-down': '<path d="m6 9 6 6 6-6"></path>',
  plus: '<path d="M12 5v14M5 12h14"></path>',
  'auto-plan':
    '<path d="M20 11a8 8 0 0 0-14.9-4M4 4v4h4"></path><path d="M4 13a8 8 0 0 0 14.9 4M20 20v-4h-4"></path>',
  close: '<path d="M7 7l10 10M17 7 7 17"></path>',
  'thumbs-up':
    '<path d="M7 11v9H4v-9zM7 11l4-7a2 2 0 0 1 2 2v4h5.5a2 2 0 0 1 2 2.3l-1.2 6A2 2 0 0 1 17.3 20H7"></path>',
  'thumbs-down':
    '<path d="M7 11v9H4v-9zM7 11l4-7a2 2 0 0 1 2 2v4h5.5a2 2 0 0 1 2 2.3l-1.2 6A2 2 0 0 1 17.3 20H7" transform="rotate(180 12 12)"></path>',
  search: '<circle cx="11" cy="11" r="6.5"></circle><path d="m20 20-4.2-4.2"></path>',
  clock: '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path>',
  flame:
    '<path d="M12 3c2 3 5 5 5 9a5 5 0 0 1-10 0c0-2 1-3.5 2-4.5.3 1.7 1.2 2.5 2 2.5 0-2.5-.5-4.5 1-7z"></path>',
  users:
    '<circle cx="9" cy="8" r="3.5"></circle><path d="M2.5 20a6.5 6.5 0 0 1 13 0"></path><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"></path>',
  heart:
    '<path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7.3a4.3 4.3 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z"></path>',
  minus: '<path d="M5 12h14"></path>',
  copy: '<rect x="8" y="8" width="12" height="12" rx="2.5"></rect><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"></path>',
  check: '<path d="m5 12.5 4.5 4.5L19 7.5"></path>',
  share:
    '<path d="M12 15V4M8 8l4-4 4 4"></path><path d="M5 12v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"></path>',
  import:
    '<path d="M12 4v11M7 10l5 5 5-5"></path><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"></path>',
}
