# CourtFlow AI Workflow Inventory

Generated: 2026-06-13

**Total workflows:** 12 (4 Supreme Court / divorce, 8 Family Court)

Priority columns: `routing_priority` drives Court Routing Engine ordering; `intake_priority` drives Intake Agent ordering (safety-sensitive workflows rank highest).

---

## Supreme Court Workflows (category: divorce)

### uncontested_divorce_no_children_nyc

- **Category:** divorce
- **Court:** supreme_court
- **Issue type:** divorce
- **Counties:** New York, Kings, Queens, Bronx, Richmond
- **Triggers:** divorce, uncontested divorce, agreed divorce, we both agree, divorce without children, no children divorce
- **Entry questions:** Do you want a divorce? / Does your spouse agree to the divorce? / Do you have any children under 21?
- **Routing priority:** 90 | **Intake priority:** 90
- **Stages:** commencement, service, calendar, judgment
- **Outcomes:** judgment_of_divorce, property_division_terms
- **Required forms:** UD-1, UD-2, UD-3, UD-4, UD-5, UD-6, UD-7, UD-9, UD-10, UD-12, UD-13, UD-11, UD-14
- **Supporting documents:** marriage_certificate, proof_of_residency, separation_agreement_or_stipulation, financial_disclosures, statement_of_net_worth
- **Workflow enum:** UNCONTESTED_DIVORCE_NO_CHILDREN

### uncontested_divorce_children_nyc

- **Category:** divorce
- **Court:** supreme_court
- **Issue type:** divorce
- **Counties:** New York, Kings, Queens, Bronx, Richmond
- **Triggers:** divorce, agreed divorce, we both agree, divorce with children, uncontested divorce with children, divorce and custody, divorce and child support
- **Entry questions:** Do you want a divorce? / Does your spouse agree to the divorce? / Do you have children under 21?
- **Routing priority:** 100 | **Intake priority:** 90
- **Stages:** commencement, service, calendar, judgment
- **Outcomes:** judgment_of_divorce, custody_terms, visitation_terms, child_support_terms
- **Required forms:** UD-1, UD-2, UD-3, UD-4, UD-5, UD-6, UD-7, UD-8(1), UD-8(2), UD-8(3), UD-8a, UD-9, UD-10, UD-12, UD-13, UD-11, UD-14
- **Supporting documents:** marriage_certificate, birth_certificates, proof_of_residency, separation_agreement_or_stipulation, parenting_plan, financial_disclosures, statement_of_net_worth, proof_of_income
- **Includes:** custody, visitation, child_support, maintenance, property_division
- **Workflow enum:** UNCONTESTED_DIVORCE_WITH_CHILDREN

### contested_divorce_nyc

- **Category:** divorce
- **Court:** supreme_court
- **Issue type:** divorce
- **Counties:** New York, Kings, Queens, Bronx, Richmond
- **Triggers:** contested divorce, divorce dispute, spouse will not agree, divorce trial, matrimonial action
- **Entry questions:** Do you want a divorce? / Does your spouse disagree with the divorce or its terms? / Has your spouse filed an answer or retained an attorney?
- **Routing priority:** 95 | **Intake priority:** 90
- **Stages:** commencement, service, answer, preliminary_conference, discovery, compliance_conference, settlement, trial, judgment
- **Outcomes:** judgment_of_divorce, custody_terms, visitation_terms, child_support_terms, maintenance_award, property_division_terms
- **Required forms:** UD-1, UD-2, UD-3, UD-5, UD-13, UD-9, UD-10, UD-11, UD-14
- **Supporting documents:** marriage_certificate, birth_certificates, financial_disclosures, statement_of_net_worth, proof_of_income, tax_returns, existing_court_orders, property_deeds, retirement_account_statements
- **Includes:** custody, visitation, child_support, maintenance, property_division, discovery, motion_practice
- **Workflow enum:** CONTESTED_DIVORCE

### default_divorce_nyc

- **Category:** divorce
- **Court:** supreme_court
- **Issue type:** divorce
- **Counties:** New York, Kings, Queens, Bronx, Richmond
- **Triggers:** default divorce, no answer, defendant did not respond, default judgment divorce
- **Entry questions:** Do you want a divorce? / Was your spouse served with divorce papers? / Did your spouse fail to respond within the required time?
- **Routing priority:** 85 | **Intake priority:** 90
- **Stages:** commencement, service, default, judgment
- **Outcomes:** judgment_of_divorce
- **Required forms:** UD-1, UD-2, UD-3, UD-5, UD-6, UD-9, UD-10, UD-11, UD-14
- **Supporting documents:** marriage_certificate, proof_of_service, birth_certificates, affidavit_of_regularity
- **Workflow enum:** DEFAULT_DIVORCE

---

## Family Court Workflows (category: family_court)

### custody_nyc

- **Category:** family_court
- **Court:** family_court
- **Issue type:** custody
- **Counties:** New York, Kings, Queens, Bronx, Richmond
- **Triggers:** custody, child custody, legal custody, physical custody, parenting time
- **Entry questions:** Are you seeking custody of a child? / Is there an active divorce case?
- **Routing priority:** 50 | **Intake priority:** 50
- **Routing rules:** active_divorce=true → uncontested_divorce_children_nyc; active_divorce=contested → contested_divorce_nyc
- **Stages:** petition, service, hearing, order, modification, violation, enforcement
- **Outcomes:** custody_order, visitation_order
- **Required forms:** GF-17, GF-40, GF-41
- **Supporting documents:** birth_certificates, existing_court_orders, school_records, proof_of_residency
- **Workflow enum:** CUSTODY

### visitation_nyc

- **Category:** family_court
- **Court:** family_court
- **Issue type:** visitation
- **Counties:** New York, Kings, Queens, Bronx, Richmond
- **Triggers:** visitation, parenting time, access, see my child, visitation schedule
- **Entry questions:** Are you seeking visitation or parenting time with a child? / Is there an active divorce case?
- **Routing priority:** 45 | **Intake priority:** 45
- **Routing rules:** active_divorce=true → uncontested_divorce_children_nyc; active_divorce=contested → contested_divorce_nyc
- **Stages:** petition, service, hearing, order, modification, violation, enforcement
- **Outcomes:** visitation_order
- **Required forms:** GF-17, GF-40, GF-41
- **Supporting documents:** birth_certificates, existing_court_orders, parenting_plan
- **Workflow enum:** VISITATION

### child_support_nyc

- **Category:** family_court
- **Court:** family_court
- **Issue type:** child_support
- **Counties:** New York, Kings, Queens, Bronx, Richmond
- **Triggers:** child support, support order, support payments, pay child support, collect child support
- **Entry questions:** Are you seeking child support? / Is there an active divorce case?
- **Routing priority:** 50 | **Intake priority:** 50
- **Routing rules:** active_divorce=true → uncontested_divorce_children_nyc; active_divorce=contested → contested_divorce_nyc
- **Stages:** petition, service, hearing, order, modification, enforcement
- **Outcomes:** child_support_order
- **Required forms:** 4-3, 4-11, 4-12
- **Supporting documents:** birth_certificates, proof_of_income, tax_returns, pay_stubs, existing_court_orders
- **Workflow enum:** CHILD_SUPPORT

### family_offense_nyc

- **Category:** family_court
- **Court:** family_court
- **Issue type:** family_offense
- **Counties:** New York, Kings, Queens, Bronx, Richmond
- **Triggers:** family offense, domestic violence, abuse, harassment, assault by family member
- **Entry questions:** Has a family or household member harmed or threatened you? / Are you seeking protection from that person? / What is your relationship to that person?
- **Routing priority:** 80 | **Intake priority:** 95
- **Stages:** petition, temporary_order_of_protection, hearing, final_order_of_protection, violation, enforcement
- **Outcomes:** temporary_order_of_protection, final_order_of_protection
- **Required forms:** 8-2
- **Supporting documents:** police_reports, photographs, medical_records, witness_statements, existing_orders_of_protection
- **Workflow enum:** FAMILY_OFFENSE

### order_of_protection_nyc

- **Category:** family_court
- **Court:** family_court
- **Issue type:** order_of_protection
- **Counties:** New York, Kings, Queens, Bronx, Richmond
- **Triggers:** order of protection, restraining order, stay away order, protection order, OP
- **Entry questions:** Are you seeking an order of protection? / Is the person you need protection from a family or household member? / Are you in immediate danger?
- **Routing priority:** 85 | **Intake priority:** 100
- **Stages:** petition, temporary_order, hearing, final_order, extension, enforcement
- **Outcomes:** temporary_order_of_protection, final_order_of_protection
- **Required forms:** 8-2
- **Supporting documents:** police_reports, photographs, medical_records, existing_orders_of_protection
- **Workflow enum:** ORDER_OF_PROTECTION

### paternity_nyc

- **Category:** family_court
- **Court:** family_court
- **Issue type:** paternity
- **Counties:** New York, Kings, Queens, Bronx, Richmond
- **Triggers:** paternity, establish father, acknowledgment of paternity, who is the father, DNA test
- **Entry questions:** Are you trying to establish who the legal father of a child is? / Is there an active divorce case?
- **Routing priority:** 40 | **Intake priority:** 40
- **Stages:** petition, service, hearing, genetic_testing, order
- **Outcomes:** order_of_filiation, paternity_establishment
- **Required forms:** 5-1
- **Supporting documents:** birth_certificate, acknowledgment_of_paternity, genetic_testing_results
- **Workflow enum:** PATERNITY

### guardianship_nyc

- **Category:** family_court
- **Court:** family_court
- **Issue type:** guardianship
- **Counties:** New York, Kings, Queens, Bronx, Richmond
- **Triggers:** guardianship, legal guardian, guardian of the person, kinship guardian, standby guardian
- **Entry questions:** Are you seeking to become the legal guardian of a minor? / Who currently cares for the child?
- **Routing priority:** 35 | **Intake priority:** 35
- **Stages:** petition, service, investigation, hearing, order
- **Outcomes:** guardianship_order
- **Required forms:** 6-1
- **Supporting documents:** birth_certificate, death_certificate_of_parent, consent_of_parent, home_study_report, background_check_results
- **Workflow enum:** GUARDIANSHIP

### adoption_nyc

- **Category:** family_court
- **Court:** family_court
- **Issue type:** adoption
- **Counties:** New York, Kings, Queens, Bronx, Richmond
- **Triggers:** adoption, adopt a child, stepparent adoption, agency adoption, private placement adoption
- **Entry questions:** Are you seeking to adopt a child? / Is this an agency or private-placement adoption?
- **Routing priority:** 30 | **Intake priority:** 30
- **Stages:** petition, consent_and_notice, investigation, hearing, order
- **Outcomes:** adoption_order
- **Required forms:** 1-A, 1-C
- **Supporting documents:** birth_certificate, marriage_certificate, home_study_report, surrender_or_consent_documents, agency_approval_letter
- **Workflow enum:** ADOPTION
