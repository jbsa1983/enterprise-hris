import type { Config } from "tailwindcss";

const config: Config = {
  content: [
    "./app/**/*.{js,ts,jsx,tsx,mdx}",
    "./components/**/*.{js,ts,jsx,tsx,mdx}",
  ],
  theme: {
    extend: {
      colors: {
        // GEEK Group brand palette (the four-color set).
        geek: {
          red: "#EA4335",
          amber: "#F9AB00",
          green: "#34A853",
          blue: "#4285F4",
          bluedark: "#1A73E8",
        },
        // Primary action colour = GEEK blue; brand-900 = neutral dark sidebar.
        brand: {
          50: "#eaf1fe",
          100: "#d3e2fd",
          600: "#1a73e8",
          700: "#155ec1",
          800: "#124c9c",
          900: "#0f172a",
        },
      },
    },
  },
  plugins: [],
};

export default config;
