// GEEK Group brand colors (the four-color set), for inline styling & charts.
export const GEEK = {
  red: "#EA4335",
  amber: "#F9AB00",
  green: "#34A853",
  blue: "#4285F4",
  blueDark: "#1A73E8",
};

// Ordered palette for cycling (bars, categories, etc.).
export const GEEK_CYCLE = [GEEK.red, GEEK.amber, GEEK.green, GEEK.blue];

export function geekColor(i: number): string {
  return GEEK_CYCLE[i % GEEK_CYCLE.length];
}
