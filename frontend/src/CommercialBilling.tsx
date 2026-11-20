import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

interface Invoice {
  id: string;
  subscription_id: string;
  status: string;
  outstanding_paise: number;
  paid_paise: number;
  due_on: string;
}

interface LedgerEntry {
  id: string;
  payment_id: string;
  receipt_reference: string;
  entry_type: string;
  delta_paise: number;
  reason: string | null;
}

interface Page<T> {
  data: T[];
  next_page_url: string | null;
}

const inr = new Intl.NumberFormat("en-IN", {
  style: "currency",
  currency: "INR",
});
function money(paise: number, negativeAmountStyle?: string | null): string {
  if (paise < 0 && negativeAmountStyle === "parentheses") {
    return "(" + inr.format(Math.abs(paise) / 100) + ")";
  }
  return inr.format(paise / 100);
}

export function CommercialBilling() {
  const [invoices, setInvoices] = useState<Page<Invoice>>();
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<{
    invoice: Invoice;
    history: Page<LedgerEntry>;
    presentation?: { negative_amount: string | null };
  }>();
  const [historyPage, setHistoryPage] = useState(1);
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function run(action: () => Promise<void>) {
    setNotice("");
    setBusy(true);
    try {
      await action();
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function list(number: number) {
    setInvoices(
      await (await portalRequest("commercial/invoices?page=" + number)).json(),
    );
    setPage(number);
  }

  async function load(id: string, number = 1) {
    setSelected(
      await (
        await portalRequest(
          "commercial/invoices/" +
            encodeURIComponent(id) +
            "/payments?page=" +
            number,
        )
      ).json(),
    );
    setHistoryPage(number);
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = Object.fromEntries(new FormData(form).entries()) as Record<
      string,
      string
    >;
    const operation = form.dataset.operation;
    return run(async () => {
      if (operation === "billing-issue") {
        const invoice = (await (
          await portalRequest("commercial/invoices", fields)
        ).json()) as Invoice;
        await list(1);
        await load(invoice.id);
        setNotice("Billing record saved.");
      } else {
        if (!selected) return;
        const invoice = selected.invoice.id;
        const path =
          "commercial/invoices/" + encodeURIComponent(invoice) + "/payments";
        if (operation === "billing-reverse") {
          await portalRequest(
            path + "/" + encodeURIComponent(fields.payment) + "/reverse",
            { reason: fields.reason },
          );
          setNotice("Payment reversal recorded.");
        } else {
          await portalRequest(path, fields);
          setNotice("Payment recorded.");
        }
        await load(invoice);
        await list(page);
      }
      form.reset();
    });
  }

  return (
    <details>
      <summary>Subscription billing and payments</summary>
      <form data-operation="billing-issue" onSubmit={submit}>
        <label>
          Subscription ID
          <input name="subscription_id" required />
        </label>
        <label>
          Payment due on (India calendar)
          <input name="due_on" type="date" required />
        </label>
        <button disabled={busy}>Issue billing record</button>
      </form>
      <button disabled={busy} onClick={() => run(() => list(1))}>
        Load billing records
      </button>
      {invoices && (
        <>
          <ul>
            {invoices.data.map((invoice) => (
              <li key={invoice.id}>
                <button
                  disabled={busy}
                  onClick={() => run(() => load(invoice.id))}
                >
                  View invoice {invoice.id}
                </button>{" "}
                {invoice.status} · {money(invoice.outstanding_paise)}{" "}
                outstanding
              </li>
            ))}
          </ul>
          {page > 1 && (
            <button disabled={busy} onClick={() => run(() => list(page - 1))}>
              Previous invoices
            </button>
          )}
          {invoices.next_page_url && (
            <button disabled={busy} onClick={() => run(() => list(page + 1))}>
              Next invoices
            </button>
          )}
        </>
      )}
      {selected && (
        <section aria-label="Selected invoice">
          <h3>Invoice {selected.invoice.id}</h3>
          <p>
            {selected.invoice.status} · {money(selected.invoice.paid_paise)}{" "}
            paid · {money(selected.invoice.outstanding_paise)} outstanding
          </p>
          <form data-operation="billing-payment" onSubmit={submit}>
            <label>
              Received amount in paise (INR)
              <input name="amount_paise" type="number" min="1" required />
            </label>
            <label>
              Receipt reference
              <input name="receipt_reference" maxLength={120} required />
            </label>
            <button disabled={busy}>Record payment</button>
          </form>
          <form data-operation="billing-reverse" onSubmit={submit}>
            <label>
              Payment ID
              <input name="payment" required />
            </label>
            <label>
              Reversal reason
              <input name="reason" maxLength={500} required />
            </label>
            <button disabled={busy}>Record reversal</button>
          </form>
          <ul aria-label="Payment ledger">
            {selected.history.data.map((entry) => (
              <li key={entry.id}>
                {entry.entry_type} · {entry.payment_id} ·{" "}
                {entry.receipt_reference} ·{" "}
                {money(
                  entry.delta_paise,
                  selected.presentation?.negative_amount,
                )}
                {entry.reason && <p>Reversal reason: {entry.reason}</p>}
              </li>
            ))}
          </ul>
          {historyPage > 1 && (
            <button
              disabled={busy}
              onClick={() =>
                run(() => load(selected.invoice.id, historyPage - 1))
              }
            >
              Previous ledger entries
            </button>
          )}
          {selected.history.next_page_url && (
            <button
              disabled={busy}
              onClick={() =>
                run(() => load(selected.invoice.id, historyPage + 1))
              }
            >
              Next ledger entries
            </button>
          )}
        </section>
      )}
      <p role="status">{notice}</p>
    </details>
  );
}
