import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { fileURLToPath, URL } from "node:url";

export default defineConfig({
  plugins: [react()],
  base: "./",
  resolve: {
    alias: { "@": fileURLToPath(new URL("./src", import.meta.url)) },
  },
  server: {
    // Local dev: proxy API calls to the PHP test server on :8090.
    proxy: { "/api": "http://localhost:8090" },
  },
  build: { outDir: "dist", emptyOutDir: true },
});
