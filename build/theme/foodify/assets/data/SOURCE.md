# pincodes.json

India Post, **All India Pincode Directory**, published on data.gov.in under the
**Government Open Data License – India** (GODL-India). Attribution is a condition
of that licence; this file and the `source` field inside the JSON carry it.

Built by `tools/build-pincodes.py`, which also corrects what older snapshots get
wrong for checkout — Telangana districts filed under Andhra Pradesh, Leh and
Kargil under Jammu & Kashmir — and gives NO state for a PIN filed under two.

The copy in the repo is a 2015 snapshot (npm `india-pincode-lookup` 1.0.3).
**Before launch, download the current directory from data.gov.in and rebuild:**

    python3 tools/build-pincodes.py ~/Downloads/<all-india-pincode-directory>.csv

A PIN missing from the table is not an error: checkout simply does not fill
city and state, and the customer types them.
