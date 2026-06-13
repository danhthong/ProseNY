# CourtFlow AI Workflow Outcomes

Expected procedural outcomes for each workflow. This document supports the future Procedural Navigator, Timeline Engine, and completion tracking. Outcomes are the terminal results a user can expect when a workflow runs to completion.

---

## Supreme Court (divorce)

### uncontested_divorce_no_children_nyc

- → judgment_of_divorce
- → property_division_terms

### uncontested_divorce_children_nyc

- → judgment_of_divorce
- → custody_terms
- → visitation_terms
- → child_support_terms

### contested_divorce_nyc

- → judgment_of_divorce
- → custody_terms
- → visitation_terms
- → child_support_terms
- → maintenance_award
- → property_division_terms

### default_divorce_nyc

- → judgment_of_divorce

---

## Family Court

### custody_nyc

- → custody_order
- → visitation_order

### visitation_nyc

- → visitation_order

### child_support_nyc

- → child_support_order

### family_offense_nyc

- → temporary_order_of_protection
- → final_order_of_protection

### order_of_protection_nyc

- → temporary_order_of_protection
- → final_order_of_protection

### paternity_nyc

- → order_of_filiation
- → paternity_establishment

### guardianship_nyc

- → guardianship_order

### adoption_nyc

- → adoption_order
