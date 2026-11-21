import { defineConfig } from "vite";

export default defineConfig({
  server: {
    host: "0.0.0.0",
    port: 3030,
    strictPort: true,
    // HMR websocket does not survive the Docker port publish, so the client
    // reconnects and full-reloads repeatedly, wiping form input mid-typing.
    hmr: false,
    proxy: {
      "/api": {
        target: "http://127.0.0.1:8080",
        changeOrigin: true,
      },
    },
  },
});
