/** @type {import('tailwindcss').Config} */
export default {
  content: ["./index.html", "./src/**/*.{js,ts,jsx,tsx}"],
  theme: {
    extend: {
      colors: {
        geek: { red: "#EA4335", amber: "#F9AB00", green: "#34A853", blue: "#4285F4", bluedark: "#1A73E8" },
        brand: { 50: "#eaf1fe", 100: "#d3e2fd", 600: "#1a73e8", 700: "#155ec1", 800: "#124c9c", 900: "#0f172a" },
      },
    },
  },
  plugins: [],
};
