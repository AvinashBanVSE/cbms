import { useEffect, useState } from "react";
import { portalRequest } from "./api";

interface OrganizationPage {
  data: {
    id: string;
    tenant_id: string;
    professional_name: string;
    operational_state: string;
  }[];
  next_page_url: string | null;
}

export function ProviderDirectory() {
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<OrganizationPage>();
  const [notice, setNotice] = useState("");

  useEffect(() => {
    let active = true;
    setResult(undefined);
    setNotice("");
    portalRequest("provider-organizations?page=" + page)
      .then((response) => response.json())
      .then((data) => {
        if (active) setResult(data);
      })
      .catch((error: Error) => {
        if (active) setNotice(error.message);
      });
    return () => {
      active = false;
    };
  }, [page]);

  return (
    <>
      {result && (
        <>
          {result.data.length ? (
            <ul aria-label="Served Organizations">
              {result.data.map((item) => (
                <li key={item.id}>
                  {item.professional_name} · {item.operational_state} · Tenant{" "}
                  {item.tenant_id}
                </li>
              ))}
            </ul>
          ) : (
            <p>No Organizations on this page.</p>
          )}
          {page > 1 && (
            <button onClick={() => setPage(page - 1)}>
              Previous Organizations
            </button>
          )}
          {result.next_page_url && (
            <button onClick={() => setPage(page + 1)}>
              Next Organizations
            </button>
          )}
        </>
      )}
      <p role="status">{notice}</p>
    </>
  );
}
