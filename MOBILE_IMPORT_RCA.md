# Sales CSV Mobile Missing — Root Cause Analysis

**Date:** 2026-07-30  
**Scope:** Sheet52 Sales CSV → Master Data (`ca_masters`)  
**Code changes during investigation:** none  

---

## 1. Root cause (summary)

Remarks appear without mobile because **they use separate code paths**.

| Field | Path |
|-------|------|
| **Mobile** | CSV → `mapSheetRow` → `PhoneNormalizationService::normalize` → mapping engine → `attachSalesMobile` / `toCaMasterAttributes` → `ca_masters.mobile_no` |
| **Remarks** | CSV → `mapSheetRow` (any header containing `remark`) → **after** create/update → `applySalesRemarksAndEmail` only |

So a row can:

1. Fail mobile normalization (empty / short / non-digit), **still** create or merge the firm, **then** write remarks.
2. Store a valid number only in **Alternate Mobile** while **Mobile** stays empty — Master Data “Mobile” column shows `—` even though Alt has the number.

This is **not** a Master Data UI hide bug for true NULLs: live DB has `mobile_no = NULL`.

**Classification:** Validation + import placement bug (not UI, not DB corruption, not “overwrite with NULL”).

---

## 2. Exact locations

### A. Remarks succeed independently of mobile

| Field | Value |
|-------|--------|
| **File** | `app/Services/Bulk/SalesSheetMasterImportService.php` |
| **Function** | `import()` decision loop + `applySalesRemarksAndEmail()` |
| **Lines** | 158–162 (call), 408–439 (remarks write; **no mobile**) |

```158:162:app/Services/Bulk/SalesSheetMasterImportService.php
                if ($caId > 0 && in_array($type, [
                    MasterMappingDecision::DECISION_AUTO_CREATE,
                    MasterMappingDecision::DECISION_AUTO_UPDATE,
                ], true)) {
                    $this->applySalesRemarksAndEmail($caId, $row, $type === MasterMappingDecision::DECISION_AUTO_CREATE);
```

### B. Mobile nullified before engine

| Field | Value |
|-------|--------|
| **File** | `app/Services/Bulk/SalesSheetMasterImportService.php` |
| **Function** | `mapSheetRow()` |
| **Lines** | 261–278 |

```261:278:app/Services/Bulk/SalesSheetMasterImportService.php
        $mobileRaw = $get('Mobile No', 'Mobile', 'mobile_no');
        // ...
        $mobile = $this->phones->normalize($mobileRaw);
```

| Field | Value |
|-------|--------|
| **File** | `app/Services/Leads/PhoneNormalizationService.php` |
| **Function** | `normalize()` |
| **Lines** | 7–30 |

Returns `null` when: blank, no digits, or fewer than 10 digits (after stripping non-digits / keeping last 10 / dropping leading 0).

**Answers to checklist:**

| # | Question | Answer |
|---|----------|--------|
| 1 | Mobile column read? | **Yes** — header `Mobile No` |
| 2 | Mapped? | **Yes** — exact aliases `Mobile No` / `Mobile` / `mobile_no` |
| 3 | Skip invalid? | **Yes** — normalize → null; row may still import |
| 4 | Reject &lt;10 digits? | **Yes** |
| 5 | Remove spaces? | **Yes** — non-digits stripped |
| 6 | Remove +91? | **Yes** — last 10 digits |
| 7 | Remove leading 0? | **Yes** — 11-digit `0…` |
| 8 | Reject duplicates? | Intra-file phone dedupe keeps best row; cross-master conflict can skip merge |
| 9 | Overwrite with NULL? | **No** — empty-only merge; `attachSalesMobile` unsets attrs instead of writing null over filled |
| 10 | Remarks without mobile? | **Yes** — by design of post-step |

### C. Empty primary + filled alternate (looks “missing” in Mobile column)

| Field | Value |
|-------|--------|
| **File** | `app/Services/Bulk/SalesSheetMasterImportService.php` + `MasterDataMatchingService::normalizePayload` |
| **Behavior** | Empty `Mobile No` + filled `Alternate Mobile No` → `mobile_no=null`, `alternate_mobile_no=<number>` |
| **UI** | `pages.js` separate columns Mobile vs Alt Mobile; Mobile cell uses only `lead.mobile_no` |

### D. Merge: do not steal filled slots

| Field | Value |
|-------|--------|
| **File** | `app/Services/Mapping/MasterDataMappingService.php` |
| **Function** | `attachSalesMobile()` |
| **Lines** | 896–930 |

`skipped_slots_full` discards CSV mobile when both primary and alt already filled; remarks still applied afterward.

---

## 3. Evidence — live DB (2026-07-30)

| Metric | Count |
|--------|------:|
| `ca_masters` with `sales_remarks` | 15,639 |
| Remarks + **empty primary** `mobile_no` | **43** |
| … of which **have alternate** | **27** (number exists, wrong column) |
| … of which **no primary and no alt** | **16** |
| Source `Sales CSV Import` (source_id=4) masters | 16,330 |
| … empty primary mobile | 73 |
| … empty primary + remarks | 39 |

`sales_import_rows` / `sales_list_entries` = **0** on live (sheet went through `master:import-sales-sheet`, not sales staging tables).

Sample (CSV → DB):

| Firm | CSV Mobile | CSV Alt | Imported Mobile | Imported Alt | Reason |
|------|------------|---------|-----------------|--------------|--------|
| B.K. Goel & Associates | *(empty)* | *(empty)* | NULL | NULL | CSV had no phone; remarks `sd ankit` still applied |
| R.Tripathi & Associates | `921964048_` | *(empty)* | NULL | NULL | &lt;10 digits after strip → normalize null; remark `digit missing` |
| K.D.Bhandari Associates | `935803497_` | *(empty)* | NULL | NULL | same |
| CA Dimpal Sahu & Associates | `CNC` | *(empty)* | NULL | NULL | non-digit → null; remarks kept |
| G M R & Co | `91-2477797` | *(empty)* | NULL | NULL | 9 digits after strip → null; remark `invalid no` |
| Kamkar Associates LLP | *(empty)* | *(empty)* | NULL | NULL | empty mobile + remarks |
| D P Shewale & Co LLP | *(empty)* | `020-26872345` | NULL | `020-26872345` | Valid number only in Alt → Mobile column blank |
| RNM India | *(empty)* | landline | NULL | `1143192000` | same pattern |

**Where mobile first disappears:** at `PhoneNormalizationService::normalize` (or never present in CSV Mobile column). It does **not** disappear in the UI after a successful DB write of primary mobile.

---

## 4. Evidence — Sheet52 CSV

| Metric | Count |
|--------|------:|
| Total data rows | 35,914 |
| Rows with remarks | 26,039 |
| Remarks + empty Mobile No | 45 (25 of these have Alt) |
| Remarks + mobile null after normalize | 32 |
| Remarks + valid normalized mobile | 25,962 |

Headers confirmed: `Mobile No`, `Alternate Mobile No`, `Remarks 1 ` … `remark 8`. Mapping aliases match.

---

## 5. Duplicate / merge

- Does **not** clear an existing valid primary with NULL.
- May put a new CSV number on **alternate** (`added_alternate`) or skip (`skipped_slots_full`).
- `LeadPhoneNumberRepository::syncForLead` skips registry insert on conflict; does not wipe `ca_masters.mobile_no`.

---

## 6. Issue type

| Type | Applies? |
|------|----------|
| Import bug | **Yes** — alt-only not promoted to empty primary |
| Mapping bug | Partial — Mobile aliases OK; remarks looser than mobile |
| Validation bug | **Yes** — intentional reject of short/garbage; remarks still saved |
| Duplicate bug | Secondary only (`skipped_slots_full`) |
| UI bug | **No** for NULL primary; Alt column can hold the number |
| Database bug | **No** |

---

## 7. Safe fix plan (implemented after this RCA)

1. **Import-time:** If primary normalizes to null and alternate is valid → promote alternate to primary (empty-only).
2. **Merge-time:** When attaching sales phones, if primary empty, fill from incoming primary **or** alternate (never overwrite filled primary).
3. **Repair command:** Re-scan Sales CSV + live masters: promote alt→primary where safe; empty-fill primary from CSV when valid and free; write before/after stats + per-record repair report.
4. Preserve remarks, email, call history, duplicate detection, no new duplicate masters.
