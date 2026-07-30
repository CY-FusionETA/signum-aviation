# WazzOCR — AI prompt add-on (Signum Aviation account)

Paste the block below into **WazzOCR → Signum Aviation → Settings & users → AI
prompt add-on → Add prompt**. It's combined with WazzOCR's general base prompt +
the account's chart of accounts, and only affects this account.

It makes the model (1) write every bill line in Signum's standard description
convention and (2) reliably pull the airport ICAO, service date, tail number and
correct currency from aviation ground-service invoices.

> It does **not** fill the trip number or the client-recharge assignment — those
> come from the LEON master list (Skyledger), added on top later. Leave Reference
> empty when no reference is on the document.

---

```
Signum Aviation — bill extraction rules (this account only).

These are aviation ground-service invoices (ground handling; landing; parking;
navigation; overflight/landing permits; passenger & crew charges; fuel). Apply
these on top of the base rules.

1. LINE DESCRIPTION — always format the main line description exactly as:
   "<Charge type> at <Airport ICAO> on <DD/MM/YYYY> for <Aircraft tail>"
   Example: "Ground Handling at VHHH on 30/03/2026 for MAL191".
   • Charge type — the service billed (Ground Handling; Landing, Handling &
     Associated Charges; Navigation; Overflight Permit; Parking; etc.).
   • Airport ICAO — the 4-letter ICAO of the airport the service was at
     (VHHH, EINN, EGGW…). If only an IATA/city name is shown, convert to ICAO.
   • Date — the service/arrival date as DD/MM/YYYY. If a date range is shown,
     use the arrival date.
   • Aircraft tail — the registration/tail number (MAL191, N488MH…).

2. FIELDS to extract for the bill:
   • Supplier — the company that issued the invoice (e.g. "ASA South China Ltd").
   • Invoice number, invoice date, due date.
   • Currency — the currency of the TOTAL BALANCE DUE. If the invoice quotes an
     exchange rate (e.g. "HKD7.44/USD1.00") but the amount due is in USD, use USD.
     Never convert the amount yourself.
   • Total — the total amount payable.

3. REFERENCE — if the document shows a trip/booking/reference number, put it in
   the bill Reference. If none is visible, leave Reference EMPTY (the trip number
   is assigned later from the flight schedule; do not guess it).

4. TAX — use the tax shown on the invoice. UK invoices may carry 20% VAT; most
   non-UK aviation handling is tax-exempt / zero-rated. If no tax is shown, use no
   tax. Never invent a tax rate.

5. If the airport ICAO, service date, or tail number cannot be found, still create
   the bill using whatever is available and note the missing piece in the
   description rather than guessing.
```

---

**Gotcha:** WazzOCR matches a chart-of-accounts code from the account's uploaded
COA. If the Signum WazzOCR account has no COA loaded, the bill may come through
without an account code. If so, upload Signum's COA CSV (`code,name,category`) to
the account in WazzOCR.
