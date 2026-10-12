#!/usr/bin/env python3
"""Independent checker for Logbook's golden household (Phase 45.1, #393).

Works out the finance and depreciation rows of ../expected.csv (source
`spreadsheet+script`) from ../inputs/*.csv and the written specification
only. It shares no code with the PHP app (src/) and reads nothing of it.

Standard library only; money is decimal.Decimal, never float.
Run:  python3 -I tests/Fixtures/golden/independent/golden_script.py
Writes script.csv beside this file.

Where the spec admits more than one reading and the readings give
different figures, the row's `script` is `?` and `working` lists the
candidates (see README.md beside this file).
"""

import csv
import os
from calendar import monthrange
from datetime import date, datetime, timedelta, timezone
from decimal import ROUND_FLOOR, ROUND_HALF_EVEN, ROUND_HALF_UP, Decimal as D, getcontext
from zoneinfo import ZoneInfo

getcontext().prec = 80

HERE = os.path.dirname(os.path.abspath(__file__))
GOLDEN = os.path.dirname(HERE)
INPUTS = os.path.join(GOLDEN, "inputs")

TODAY_UTC = datetime(2026, 9, 15, 8, 0, tzinfo=timezone.utc)
KM_PER_MI = D("1.609344")
MICRO = D("0.000001")
CENT = D("0.01")
DASH = "—"


def read(name):
    with open(os.path.join(INPUTS, name + ".csv"), newline="", encoding="utf-8") as f:
        return list(csv.DictReader(f))


def d(s):
    return date.fromisoformat(s)


def q(x, places, mode=ROUND_HALF_UP):
    # ROUND_HALF_UP in Python's decimal is half AWAY from zero.
    return x.quantize(D(1).scaleb(-places), rounding=mode)


def add_months(start, k):
    y, m = divmod(start.month - 1 + k, 12)
    year, month = start.year + y, m + 1
    return date(year, month, min(start.day, monthrange(year, month)[1]))


def months_between(a, b):
    """Whole months from a to b when b is a's day-of-month k months later."""
    k = (b.year - a.year) * 12 + (b.month - a.month)
    assert add_months(a, k) == b, (a, b)
    return k


# ---------------------------------------------------------------- inputs
USERS = {u["user"]: u for u in read("users")}
VEHICLES = {v["vehicle"]: v for v in read("vehicles")}
FILLUPS = read("fillups")
READINGS = read("readings")
MAINT = read("maintenance")
DOCS = read("documents")
EXPENSES = read("expenses")
VALUATIONS = read("valuations")  # file order = order added
FINANCE = {f["agreement"]: f for f in read("finance")}
FIN_EVENTS = read("finance_events")
INCIDENTS = read("incidents")


def zone(user):
    return ZoneInfo(USERS[user]["timezone"])


def local_instant(at, by, viewer):
    """`at` is wall-clock in the zone of `by`; return it in the viewer's zone."""
    naive = datetime.strptime(at, "%Y-%m-%d %H:%M")
    return naive.replace(tzinfo=zone(by)).astimezone(zone(viewer))


def today_for(user):
    return TODAY_UTC.astimezone(zone(user)).date()


def owner(v):
    return VEHICLES[v]["owner"]


def purchase_date(v):
    return d(VEHICLES[v]["purchase_date"])


def own_end(v, viewer):
    s = VEHICLES[v]["sale_date"]
    return d(s) if s else today_for(viewer)


# ---------------------------------------------------------------- mileage log
def readings(v, viewer):
    """(instant in viewer zone, local date, km); readings before purchase dropped."""
    out = []
    for r in READINGS:
        if r["vehicle"] == v:
            t = local_instant(r["at"], r["by"], viewer)
            out.append((t, D(r["odometer_km"])))
    for f in FILLUPS:
        if f["vehicle"] == v and f["odometer_km"]:
            t = local_instant(f["at"], f["by"], viewer)
            out.append((t, D(f["odometer_km"])))
    for m in MAINT:
        if m["vehicle"] == v and m["odometer_km"]:
            t = local_instant(m["date"] + " 12:00", owner(v), viewer)
            out.append((t, D(m["odometer_km"])))
    pd = purchase_date(v)
    out = [(t, t.date(), km) for (t, km) in out if t.date() >= pd]
    out.sort(key=lambda x: x[0])
    return out


def distance(v, viewer, a, b):
    rs = readings(v, viewer)
    inside = [r for r in rs if a <= r[1] <= b]
    before = [r for r in rs if r[1] < a]
    if not inside:
        return D(0)
    end = inside[-1][2]
    start = before[-1][2] if before else inside[0][2]
    return end - start


def reaches_back(v, viewer, a):
    a = max(a, purchase_date(v))
    return any(r[1] <= a for r in readings(v, viewer))


# ---------------------------------------------------------------- finance
def monthly_rate(apr, mode):
    exact = (D(1) + D(apr) / D(100)) ** (D(1) / D(12)) - D(1)
    return q(exact, 10, ROUND_HALF_UP if mode == "half_up" else ROUND_HALF_EVEN)


def finance(agreement, cfg):
    a = FINANCE[agreement]
    v = a["vehicle"]
    today = today_for(owner(v))  # finance follows the vehicle owner's zone
    r = monthly_rate(a["apr"], cfg["rate_round"])
    first = d(a["first_payment_on"])
    n = int(a["number_of_payments"])
    sched = []
    for k in range(n):
        amt = D(a["first_payment"]) if (k == 0 and a["first_payment"]) else D(a["regular_payment"])
        sched.append({"due": add_months(first, k), "amount": amt, "kind": "regular", "no": k + 1})
    if a["final_payment"]:
        sched.append({"due": d(a["final_payment_on"]), "amount": D(a["final_payment"]), "kind": "final"})

    # interest shares, each rounded to the minor unit, balance from the credit
    bal = D(a["amount_of_credit"])
    prev = d(a["started_on"])
    for p in sched:
        m = months_between(prev, p["due"])
        assert m == 1  # every gap is one month here, so simple vs compound can't differ
        p["interest"] = q(bal * r * m, 2)
        bal -= p["amount"] - p["interest"]
        prev = p["due"]
    end_balance = bal

    # annuity self-check: PV of the schedule at r against the credit
    pv_all = sum(p["amount"] / (1 + r) ** (i + 1) for i, p in enumerate(sched))

    missed = {e["due_on"] for e in FIN_EVENTS if e["agreement"] == agreement and e["kind"] == "missed"}
    late = {e["due_on"] for e in FIN_EVENTS if e["agreement"] == agreement and e["kind"] == "paid_late"}
    settled = [e for e in FIN_EVENTS if e["agreement"] == agreement and e["kind"] == "settled"]
    end_date = d(settled[0]["date"]) if settled else None
    if end_date:
        sched = [p for p in sched if p["due"] <= end_date]  # later payments leave the schedule
    for p in sched:
        due = p["due"].isoformat()
        p["paid"] = p["due"] <= today and (due not in missed or due in late)

    deposits = D(a["customer_deposit"] or 0) + D(a["dealer_contribution"] or 0)
    doc_fee = D(a["documentation_fee"] or 0)
    opt_fee = D(a["option_to_purchase_fee"] or 0)
    paid = [p for p in sched if p["paid"]]
    fees_paid = D(0)
    if doc_fee and paid and paid[0]["no"] == 1:
        fees_paid += doc_fee
    if opt_fee and any(p["kind"] == "final" for p in paid):
        fees_paid += opt_fee
    settle_amt = D(settled[0]["amount"]) if settled else D(0)
    paid_total = deposits + sum(p["amount"] for p in paid) + settle_amt + fees_paid
    cash = D(a["cash_price"])
    tap = deposits + sum(D(a["first_payment"]) if (k == 0 and a["first_payment"]) else D(a["regular_payment"]) for k in range(n)) \
        + D(a["final_payment"] or 0) + doc_fee + opt_fee

    lines = []
    for p in paid:
        lines.append((p["due"], p["interest"], "interest #%s" % p.get("no", "final")))
        if p.get("no") == 1 and doc_fee:
            lines.append((p["due"], doc_fee, "documentation fee"))
        if p["kind"] == "final" and opt_fee:
            lines.append((p["due"], opt_fee, "option fee"))
    if end_date:
        coc = paid_total - cash
        adj = coc - sum(x[1] for x in lines)
        lines.append((end_date, adj, "adjustment"))
        interest_so_far = coc
    else:
        coc = tap - cash
        interest_so_far = sum(x[1] for x in lines)

    regular_made = sum(1 for p in paid if p["kind"] == "regular")
    regular_left = sum(1 for p in sched if p["kind"] == "regular" and not p["paid"])

    settlement = None
    settlement_work = ""
    if not end_date:
        terms = []
        for p in sched:
            if p["paid"]:
                continue
            if p["due"] <= today:  # missed and owed now
                terms.append(p["amount"])
                continue
            k = 1
            while add_months(today, k) < p["due"]:
                k += 1
            terms.append(p["amount"] / (1 + r) ** k)
        if cfg["settle"] == "sum":
            settlement = q(sum(terms), 2)
        else:
            settlement = sum(q(t, 2) for t in terms)
        settlement_work = "PV of %d payments at r=%s, k=1..%d whole months" % (len(terms), r, len(terms))

    return {
        "rate": r, "end_balance": end_balance, "pv_all": pv_all, "lines": lines,
        "coc": coc, "tap": tap, "paid_total": paid_total, "interest_so_far": interest_so_far,
        "made": regular_made, "left": regular_left, "settlement": settlement,
        "settlement_work": settlement_work, "deposits": deposits, "settle_amt": settle_amt,
        "paid_count": len(paid), "end_date": end_date, "vehicle": v,
    }


# ---------------------------------------------------------------- ledger
def ledger(v, viewer, cfg):
    """Cost lines: dict(date, group, amount, start, expiry)."""
    out = []
    for f in FILLUPS:
        if f["vehicle"] == v:
            out.append({"date": local_instant(f["at"], f["by"], viewer).date(), "group": "fuel",
                        "amount": D(f["total_cost"])})
    for m in MAINT:
        if m["vehicle"] == v and D(m["cost"]) > 0:
            out.append({"date": d(m["date"]), "group": "maintenance", "amount": D(m["cost"])})
    for doc in DOCS:
        if doc["vehicle"] == v and D(doc["cost"]) > 0:
            out.append({"date": d(doc["start_on"]), "group": "documents", "amount": D(doc["cost"]),
                        "type": doc["type"], "start": d(doc["start_on"]), "expiry": d(doc["expiry_on"]) if doc["expiry_on"] else None})
    for e in EXPENSES:
        if e["vehicle"] == v:
            out.append({"date": d(e["date"]), "group": "other", "amount": D(e["amount"])})
    for ag, a in FINANCE.items():
        if a["vehicle"] == v and a["count_in_costs"] == "yes":
            for (dt, amt, _) in finance(ag, cfg)["lines"]:
                out.append({"date": dt, "group": "other", "amount": amt})
    return out


def payouts(v, a, b):
    return sum((D(i["payout"]) for i in INCIDENTS
                if i["vehicle"] == v and i["payout"] and a <= d(i["occurred_on"]) <= b), D(0))


def spread_share(line, a, b, mode):
    s, e = line["start"], line["expiry"]
    days = (e - s).days + 1
    lo, hi = max(a, s), min(b, e)
    if hi < lo:
        return D(0)
    n = (hi - lo).days + 1
    cost = line["amount"]
    if mode == "exact":
        return cost * n / days
    micro = cost / MICRO
    share = (micro / days).quantize(D(1), rounding=ROUND_FLOOR if mode == "floor" else ROUND_HALF_UP)
    total = share * n
    if hi == e:  # the last day takes the remainder
        total += micro - share * days
    return total * MICRO


def running(v, viewer, a, b, cfg, spread):
    groups = {"fuel": D(0), "maintenance": D(0), "documents": D(0), "other": D(0)}
    for ln in ledger(v, viewer, cfg):
        if spread and ln["group"] == "documents" and ln.get("expiry"):
            groups["documents"] += spread_share(ln, a, b, cfg["spread"])
        elif a <= ln["date"] <= b:
            groups[ln["group"]] += ln["amount"]
    pay = payouts(v, a, b)
    return sum(groups.values()) - pay, groups, pay


# ---------------------------------------------------------------- value curve
# #408: a value point dated D is the value at the START of D (an instant).
# The purchase day opens at the price (start of P); a valuation or sale dated
# on the purchase day is that day's closing value, i.e. the value at the start
# of P+1 (#389, #404). Several points at one instant: the last one counts.
def value_points(v):
    """(price, [(instant_date, value)...]) with instants as dates = start of that day."""
    veh = VEHICLES[v]
    P = d(veh["purchase_date"])
    pts = [(P, D(veh["purchase_price"]), "price")]
    for x in VALUATIONS:
        if x["vehicle"] == v:
            pts.append((d(x["valued_on"]), D(x["amount"]), "valuation"))
    if veh["sale_date"] and veh["sale_price"]:
        pts.append((d(veh["sale_date"]), D(veh["sale_price"]), "sale"))
    order = {"price": 0, "valuation": 1, "sale": 2}
    pts.sort(key=lambda p: (p[0], order[p[2]]))  # stable: valuations keep the order added
    at = {}
    for dt, val, kind in pts:
        if kind != "price" and dt == P:
            dt = P + timedelta(days=1)  # purchase-day valuation/sale: that day's close
        at[dt] = val
    return pts[0][1], sorted(at.items())


CURVE = {"micro": True}


def start_value(v, instant):
    """Value at the start of day `instant`, interpolated in a straight line by day."""
    _, pts = value_points(v)
    for (d1, v1), (d2, v2) in zip(pts, pts[1:]):
        if d1 <= instant <= d2:
            val = v1 + (v2 - v1) * D((instant - d1).days) / D((d2 - d1).days)
            return q(val, 6) if CURVE["micro"] else val  # each value rounded to the micro-unit, half up (#402)
    if len(pts) == 1 and instant == pts[0][0]:
        return pts[0][1]
    raise ValueError((v, instant))


def depreciation(v, a, b):
    """Period of days a..b: (loss, start instant, end instant) or None.

    Measured from the start of a to the start of b+1, cut to the first and
    latest value points; None when the period lies wholly outside them."""
    _, pts = value_points(v)
    first, last = pts[0][0], pts[-1][0]
    s_i, e_i = max(a, first), min(b + timedelta(days=1), last)
    if e_i <= s_i:
        return None
    return start_value(v, s_i) - start_value(v, e_i), s_i, e_i


# ---------------------------------------------------------------- figures
def per_dist(rate_per_km, unit):
    return rate_per_km * KM_PER_MI if unit == "money/mi" else rate_per_km


def rate(run, dist_run, dep, dist_dep, unit, cfg):
    rr = run / dist_run
    dr = (dep / dist_dep) if dep is not None else D(0)
    if cfg["perkm6"]:
        rr, dr = q(rr, 6), q(dr, 6)
    return per_dist(rr + dr, unit)


def period_bounds(v, viewer, period):
    P, E = purchase_date(v), own_end(v, viewer)
    if period == "last_12_months":
        a, b = date(2025, 10, 1), date(2026, 9, 30)
    elif period == "previous_12_months":
        a, b = date(2024, 10, 1), date(2025, 9, 30)
    else:
        y = int(period)
        a, b = date(y, 1, 1), date(y, 12, 31)
    return max(a, P), min(b, E)


def true_cost(v, viewer, period, cfg, unit):
    a, b = period_bounds(v, viewer, period)
    run, groups, pay = running(v, viewer, a, b, cfg, spread=True)
    dep = depreciation(v, a, b)
    dep_amt = dep[0] if dep else None
    dep_last_day = None
    if dep:
        if dep[2] == b + timedelta(days=1):
            dep_last_day = b  # not cut: the whole period
        elif cfg["dep_dist"] == "day_before":
            dep_last_day = dep[2] - timedelta(days=1)  # loss runs to the start of D: distance to D-1
        else:
            dep_last_day = dep[2]  # distance up to and including D (#412)
    total = run + (dep_amt or D(0))
    work = "%s..%s running %s (fuel %s, maint %s, docs spread %s, other %s, payouts -%s)" % (
        a, b, fmt_exact(run), fmt_exact(groups["fuel"]), fmt_exact(groups["maintenance"]),
        fmt_exact(groups["documents"]), fmt_exact(groups["other"]), fmt_exact(pay))
    if dep:
        work_dep = "value at start of %s %s - at start of %s %s" % (
            dep[1], fmt_exact(start_value(v, dep[1])), dep[2], fmt_exact(start_value(v, dep[2])))
    else:
        work_dep = "period outside the value points (latest %s)" % value_points(v)[1][-1][0]
    dist = distance(v, viewer, a, b)
    rb = reaches_back(v, viewer, a)
    days = (b - a).days + 1
    pd_val, pd_work = None, ""
    if not rb or dist <= 0:
        pd_work = "no per-distance: log reaches back=%s, distance %s km" % (rb, dist)
    elif days < 90:
        pd_work = "under 90 days owned in the period (%s days, #410)" % days
    else:
        dd = distance(v, viewer, a, dep_last_day) if dep else None
        pd_val = rate(run, dist, dep_amt, dd, unit, cfg)
        run_rate = per_dist(run / dist, unit)
        dep_rate = per_dist(dep_amt / dd, unit) if dep else None
        pd_work = "running %s / %s km" % (fmt_exact(run), dist) + (
            " + dep %s / %s km (%s..%s)" % (fmt_exact(dep_amt), dd, a, dep_last_day) if dep else " (no dep part)")
        if unit == "money/mi":
            pd_work += ", x 1.609344 per mi"
    if pd_val is None:
        run_rate = dep_rate = None
    return {"run": run, "dep": dep_amt, "total": total, "pd": pd_val, "work": work,
            "work_dep": work_dep, "pd_work": pd_work, "days": days, "a": a, "b": b,
            "dist": dist, "dep_rate": dep_rate, "run_rate": run_rate}


def ownership(v, viewer, cfg, unit):
    P, E = purchase_date(v), own_end(v, viewer)
    run, groups, pay = running(v, viewer, P, E, cfg, spread=False)
    price, pts = value_points(v)
    value_date, value = pts[-1]
    if value_date == P + timedelta(days=1) and len(pts) == 2:
        value_date = P  # only a purchase-day valuation/sale: dated P
    loss = price - value
    dist_owned = distance(v, viewer, P, E)
    dist_dep = distance(v, viewer, P, value_date)
    pd_val = rate(run, dist_owned, loss, dist_dep, unit, cfg)
    months = (E.year - P.year) * 12 + (E.month - P.month) + 1
    # depreciation per year: years from purchase to the value's date
    yy, mm, dd = ymd(P, value_date)
    days = (value_date - P).days
    whole_months = 12 * yy + mm
    per_year = loss * 12 / (D(whole_months) + D(dd) / D("30.436875"))  # #409
    if loss < 0:
        per_year = None  # a gain has no per year: per month is the running part alone
    per_month = run / months + (per_year / 12 if per_year is not None else D(0))
    per_month_work = "running %s / %d months owned + per year %s / 12 (loss %s x 12 / (%d whole months + %d days / 30.436875), %s..%s)" % (
        fmt_exact(run), months, fmt_exact(per_year) if per_year is not None else "none", loss, whole_months, dd, P, value_date)
    work = "%s..%s running %s (fuel %s, maint %s, docs %s, other %s, payouts -%s)" % (
        P, E, fmt_exact(run), fmt_exact(groups["fuel"]), fmt_exact(groups["maintenance"]),
        fmt_exact(groups["documents"]), fmt_exact(groups["other"]), fmt_exact(pay))
    return {"run": run, "dep": loss, "total": run + loss, "pd": pd_val, "per_month": per_month,
            "per_month_work": per_month_work,
            "work": work, "value": (value_date, value, price),
            "pd_work": "running %s / %s km + loss %s / %s km (%s..%s)%s" % (
                fmt_exact(run), dist_owned, loss, dist_dep, P, value_date,
                ", x 1.609344 per mi" if unit == "money/mi" else ""),
            "years": (yy, mm, dd, days), "months": months}


def ymd(a, b):
    y = b.year - a.year
    if add_months(a, 12 * y) > b:
        y -= 1
    m = 0
    while add_months(a, 12 * y + m + 1) <= b:
        m += 1
    dd = (b - add_months(a, 12 * y + m)).days
    return y, m, dd


def fmt_exact(x):
    return format(q(x, 6), "f")


def money(x):
    return str(q(x, 2))


def per_distance_str(x):
    return str(q(x, 3))


# ---------------------------------------------------------------- rows
BASE = {"rate_round": "half_up", "settle": "sum", "spread": "floor", "perkm6": False, "dep_dist": "on_day"}
ALTERNATIVES = {
    "rate_round": ["half_even"],
    "settle": ["each"],
    "spread": ["halfup"],
    "perkm6": [True],
}
# Readings the spec does not allow but a spreadsheet might use: noted in
# `working` when they change a figure, never turned into `?`.
NOTE_ALTERNATIVES = {
    "spread": ["exact"],
    "curve_micro": [False],
}
LABELS = {
    "rate_round": "monthly rate rounded half-even at 10 dp",
    "settle": "settlement: each discounted payment rounded",
    "spread": "document share per day",
    "perkm6": "rate per km kept to 6 places before converting",
    "dep_dist": "depreciation part's distance includes the day of the value point it is cut at",
    "curve_micro": "value-curve points rounded to the micro-unit",
}


def compute(row, cfg):
    CURVE["micro"] = cfg.get("curve_micro", True)
    fig, viewer, v, period, unit = row["figure"], row["viewer"], row["vehicle"], row["period"], row["unit"]
    if fig.startswith("finance."):
        f = finance(row["notes"], cfg)
        key = fig.split(".", 1)[1]
        if key == "payments_made":
            return str(f["made"]), "%d regular payments due on or before %s and not missed (paid late counts)%s" % (
                f["made"], f["end_date"] or today_for(owner(v)), "; settlement event not counted as a payment" if f["end_date"] else "")
        if key == "payments_remaining":
            return str(f["left"]), ("settled %s: later payments left the schedule" % f["end_date"]) if f["end_date"] \
                else "%d regular payments due after today" % f["left"]
        if key == "amount_paid":
            return money(f["paid_total"]), "deposits %s + %d payments + settlement %s + fees paid" % (
                f["deposits"], f["paid_count"], f["settle_amt"])
        if key == "cost_of_credit":
            if f["end_date"]:
                return money(f["coc"]), "ended (settled %s): everything paid %s - cash price" % (f["end_date"], f["paid_total"])
            return money(f["coc"]), "active: total amount payable %s (worked out) - cash price" % f["tap"]
        if key == "interest_so_far":
            if f["end_date"]:
                return money(f["interest_so_far"]), "ended: lines adjusted on %s to the exact cost of credit (adjustment %s)" % (
                    f["end_date"], f["lines"][-1][1])
            return money(f["interest_so_far"]), "sum of %d interest shares at r=%s, each rounded to 0.01" % (
                len(f["lines"]), f["rate"])
        if key == "settlement_estimate":
            return money(f["settlement"]), f["settlement_work"]
        if key == "equity":
            vd, val = value_points(v)[1][-1]
            return money(val - f["settlement"]), "valuation %s %s - settlement estimate %s" % (vd, val, f["settlement"])
        raise KeyError(fig)

    if fig.startswith("ownership."):
        o = ownership(v, viewer, cfg, unit)
        key = fig.split(".", 1)[1]
        if key == "total":
            return money(o["total"]), "running %s + depreciation %s; %s" % (fmt_exact(o["run"]), o["dep"], o["work"])
        if key == "depreciation":
            vd, val, price = o["value"]
            return money(o["dep"]), "price %s - value %s on %s" % (price, val, vd)
        if key == "per_distance":
            return per_distance_str(o["pd"]), o["pd_work"]
        if key == "per_month":
            return money(o["per_month"]), o["per_month_work"]
        raise KeyError(fig)

    if fig.startswith("true_cost.what_changed."):
        key = fig.rsplit(".", 1)[1]
        if period == "last_12_months_vs_previous":
            cur_p, prev_p = "last_12_months", "previous_12_months"
        else:
            y1, y0 = [x.strip() for x in period.split("vs")]
            cur_p, prev_p = y1, y0
        cur = true_cost(v, viewer, cur_p, cfg, unit)
        prev = true_cost(v, viewer, prev_p, cfg, unit)
        if cur["pd"] is None or prev["pd"] is None:
            return DASH, "no rate in %s: %s | %s" % (
                cur_p if cur["pd"] is None else prev_p, cur["pd_work"] if cur["pd"] is None else "", prev["pd_work"] if prev["pd"] is None else "")
        if cur_p != "last_12_months" and (cur["dist"] < 500 or prev["dist"] < 500):
            return DASH, "a year under 500 km (%s km, %s km)" % (cur["dist"], prev["dist"])
        if key == "total":
            note = ""
            if cur["dep"] is None or prev["dep"] is None:
                note = " (%s has no depreciation part: its rate is running costs only)" % (cur_p if cur["dep"] is None else prev_p)
            return per_distance_str(cur["pd"] - prev["pd"]), "rate %s %s - rate %s %s%s" % (
                cur_p, fmt_exact(cur["pd"]), prev_p, fmt_exact(prev["pd"]), note)
        if key == "depreciation":
            if cur["dep_rate"] is None or prev["dep_rate"] is None:
                missing = cur_p if cur["dep_rate"] is None else prev_p
                change = (cur["dep_rate"] or D(0)) - (prev["dep_rate"] or D(0))
                return per_distance_str(change), "%s has no depreciation part (after the latest value point): counts as 0 (#413); dep rate %s %s - %s %s" % (
                    missing, cur_p, fmt_exact(cur["dep_rate"] or D(0)), prev_p, fmt_exact(prev["dep_rate"] or D(0)))
            return per_distance_str(cur["dep_rate"] - prev["dep_rate"]), "dep rate %s %s - %s %s" % (
                cur_p, fmt_exact(cur["dep_rate"]), prev_p, fmt_exact(prev["dep_rate"]))
        raise KeyError(fig)

    if fig.startswith("true_cost."):
        t = true_cost(v, viewer, period, cfg, unit)
        key = fig.split(".", 1)[1]
        if key == "depreciation":
            if t["dep"] is None:
                return DASH, t["work_dep"]
            return money(t["dep"]), t["work_dep"]
        if key == "total":
            if t["dep"] is None:
                return money(t["total"]), "running costs only (no depreciation in the period); " + t["work"]
            return money(t["total"]), "running %s + dep %s; %s" % (fmt_exact(t["run"]), fmt_exact(t["dep"]), t["work"])
        if key == "per_distance":
            if t["pd"] is None:
                return DASH, t["pd_work"]
            return per_distance_str(t["pd"]), t["pd_work"]
        raise KeyError(fig)
    raise KeyError(fig)


def main():
    with open(os.path.join(GOLDEN, "expected.csv"), newline="", encoding="utf-8") as f:
        rows = [r for r in csv.DictReader(f) if r["source"] == "spreadsheet+script"]

    # self-checks
    checks = []
    for ag in FINANCE:
        fx = finance(ag, BASE)
        credit = D(FINANCE[ag]["amount_of_credit"])
        checks.append("%s: r=%s, PV(schedule)=%s vs credit %s, balance after schedule %s" % (
            ag, fx["rate"], q(fx["pv_all"], 2), credit, fx["end_balance"]))
        assert abs(fx["pv_all"] - credit) < 2, ag
        if fx["end_date"]:
            assert sum(x[1] for x in fx["lines"]) == fx["coc"]
    for v in VEHICLES:
        P = purchase_date(v)
        years = [y for y in range(P.year, 2027)]
        tot = sum((depreciation(v, *period_bounds(v, "alex", str(y)))[0] for y in years
                   if depreciation(v, *period_bounds(v, "alex", str(y)))), D(0))
        price, pts = value_points(v)
        assert tot == price - pts[-1][1], (v, tot)
        checks.append("%s: calendar years' depreciation sums to %s = price - current value" % (v, tot))

    out = []
    ambiguous = 0
    for row in rows:
        base_val, base_work = compute(row, BASE)
        sens = []
        for key, alts in ALTERNATIVES.items():
            for alt in alts:
                cfg = dict(BASE, **{key: alt})
                val, _ = compute(row, cfg)
                if val != base_val:
                    sens.append("%s=%s gives %s" % (LABELS[key], alt, val))
        notes = []
        for key, alts in NOTE_ALTERNATIVES.items():
            for alt in alts:
                val, _ = compute(row, dict(BASE, **{key: alt}))
                if val != base_val:
                    notes.append("NOTE: %s=%s (not the spec's rule) would give %s"
                                 % (LABELS[key], alt, val))
        value, work = base_val, base_work
        if notes:
            work = "; ".join(notes) + "; " + work
        if sens:
            work = "AMBIGUOUS: base (%s) gives %s; %s; %s" % (
                ", ".join("%s=%s" % kv for kv in BASE.items()), base_val, "; ".join(sens), base_work)
            value = "?"
        if value == "?":
            ambiguous += 1
        out.append({"id": row["id"], "figure": row["figure"], "viewer": row["viewer"],
                    "vehicle": row["vehicle"], "period": row["period"], "script": value, "working": work})

    with open(os.path.join(HERE, "script.csv"), "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["id", "figure", "viewer", "vehicle", "period", "script", "working"], lineterminator="\n")
        w.writeheader()
        w.writerows(out)
    for c in checks:
        print("check:", c)
    print("rows: %d, with a value: %d, '?': %d" % (len(out), len(out) - ambiguous, ambiguous))


if __name__ == "__main__":
    main()
