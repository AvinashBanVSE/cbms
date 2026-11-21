import { afterEach, expect, it, vi } from "vitest";
import { portalRequest } from "../src/api";

afterEach(() => vi.unstubAllGlobals());

it("sends a mutation with the session token and deployment code", async () => {
  const transport = vi
    .fn()
    .mockResolvedValueOnce(Response.json({ token: "session-token" }))
    .mockResolvedValueOnce(Response.json({ stage: "owner_required" }));
  vi.stubGlobal("fetch", transport);
  await portalRequest(
    "installation/setup",
    { legal_name: "CBM" },
    "deployment-code",
  );
  expect(transport.mock.calls[1]).toEqual([
    "/api/v1/installation/setup",
    {
      method: "POST",
      cache: "no-store",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": "session-token",
        "X-Setup-Code": "deployment-code",
      },
      body: '{"legal_name":"CBM"}',
    },
  ]);
});

it.each([Response.json({}), new Response(null, { status: 503 })])(
  "stops before sending a mutation when session initialization fails",
  async (response) => {
    const transport = vi.fn().mockResolvedValue(response);
    vi.stubGlobal("fetch", transport);
    await expect(portalRequest("logout", {})).rejects.toThrow(
      "Session unavailable",
    );
    expect(transport).toHaveBeenCalledTimes(1);
  },
);

it.each([
  ["services", 409, "already registered"],
  ["installation/setup", 409, "installation stage"],
  ["services", 419, "session has expired"],
  ["services", 503, "Request failed"],
])("explains %s failure %s", async (path, status, message) => {
  vi.stubGlobal("fetch", async () => new Response(null, { status }));
  await expect(portalRequest(String(path))).rejects.toThrow(String(message));
});
