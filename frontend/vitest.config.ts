import { defineConfig } from "vitest/config";

export default defineConfig({
  test: {
    coverage: {
      include: ["src/**/*.{ts,tsx}"],
      thresholds: { lines: 90, functions: 90, statements: 90, branches: 90 },
    },
  },
});
