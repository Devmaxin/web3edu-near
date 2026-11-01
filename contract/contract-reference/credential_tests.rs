use web3edu_near_contract::{Contract, CredentialStatus, ContractError};

const ISSUER: &str = "issuer.testnet";
const LEARNER: &str = "learner.testnet";
const NON_ISSUER: &str = "non-issuer.testnet";
const COURSE_ID: &str = "web3-101";
const METADATA_HASH: &str = "QmXoypizjW3WknFiJnKLwHCnL72vedxjQkDDP1mXWo6uco";

fn issuer_account() -> String {
    ISSUER.to_string()
}

fn learner_account() -> String {
    LEARNER.to_string()
}

fn non_issuer_account() -> String {
    NON_ISSUER.to_string()
}

fn new_contract() -> Contract {
    Contract::new(ISSUER).expect("contract should initialize with valid issuer")
}

#[test]
fn contract_initializes_with_issuer() {
    let contract = Contract::new(ISSUER).unwrap();
    assert_eq!(contract.get_issuer(), ISSUER);
    assert!(contract.is_initialized());
    assert_eq!(contract.credential_count(), 0);
}

#[test]
fn contract_rejects_empty_issuer() {
    let result = Contract::new("");
    assert!(
        result.is_err(),
        "empty issuer ID should be rejected"
    );
    assert_eq!(result.unwrap_err(), ContractError::InvalidIssuer);
}

#[test]
fn authorized_issue_succeeds() {
    let mut contract = new_contract();

    let credential_id = contract
        .issue_credential(
            ISSUER,
            LEARNER,
            COURSE_ID,
            Some(METADATA_HASH),
        )
        .expect("issuer should be able to issue credential");

    assert!(!credential_id.is_empty(), "credential_id should not be empty");
    assert!(
        credential_id.starts_with("cred-"),
        "credential_id should start with 'cred-'"
    );

    let cred = contract
        .get_credential(&credential_id)
        .expect("credential should exist");
    assert_eq!(cred.learner_id, LEARNER);
    assert_eq!(cred.course_id, COURSE_ID);
    assert_eq!(cred.issuer_id, ISSUER);
    assert_eq!(cred.metadata_hash, Some(METADATA_HASH.to_string()));
    assert!(!cred.revoked, "credential should not be revoked initially");
    assert!(cred.issued_at > 0, "issued_at should be set");
}

#[test]
fn unauthorized_issue_fails() {
    let mut contract = new_contract();

    let result = contract.issue_credential(
        NON_ISSUER,
        LEARNER,
        COURSE_ID,
        Some(METADATA_HASH),
    );

    assert!(
        result.is_err(),
        "non-issuer should not be able to issue credentials"
    );
    assert_eq!(result.unwrap_err(), ContractError::NotIssuer);
}

#[test]
fn empty_learner_id_rejected() {
    let mut contract = new_contract();

    let result = contract.issue_credential(ISSUER, "", COURSE_ID, None);
    assert!(
        result.is_err(),
        "empty learner_id should be rejected"
    );
    assert_eq!(result.unwrap_err(), ContractError::InvalidLearner);
}

#[test]
fn empty_course_id_rejected() {
    let mut contract = new_contract();

    let result = contract.issue_credential(ISSUER, LEARNER, "", None);
    assert!(
        result.is_err(),
        "empty course_id should be rejected"
    );
    assert_eq!(result.unwrap_err(), ContractError::InvalidCourse);
}

#[test]
fn unique_credential_ids() {
    let mut contract = new_contract();

    let id1 = contract
        .issue_credential(ISSUER, LEARNER, COURSE_ID, None)
        .unwrap();
    let id2 = contract
        .issue_credential(ISSUER, LEARNER, COURSE_ID, None)
        .unwrap();

    assert_ne!(
        id1, id2,
        "each credential should get a unique ID"
    );

    let cred1 = contract.get_credential(&id1).expect("credential 1 should exist");
    let cred2 = contract.get_credential(&id2).expect("credential 2 should exist");
    assert_ne!(
        cred1.credential_id, cred2.credential_id,
        "IDs must differ"
    );
}

#[test]
fn credential_can_be_read() {
    let mut contract = new_contract();

    let credential_id = contract
        .issue_credential(ISSUER, LEARNER, COURSE_ID, None)
        .unwrap();

    let retrieved = contract.get_credential(&credential_id);
    assert!(
        retrieved.is_some(),
        "credential should be retrievable by ID"
    );
    let cred = retrieved.unwrap();
    assert_eq!(cred.credential_id, credential_id);
    assert_eq!(cred.learner_id, LEARNER);
    assert_eq!(cred.course_id, COURSE_ID);
}

#[test]
fn correct_learner_and_course_data_stored() {
    let mut contract = new_contract();

    let course_id = "near-smart-contracts-201";
    let learner = LEARNER.to_string();

    let credential_id = contract
        .issue_credential(ISSUER, &learner, course_id, None)
        .unwrap();
    let cred = contract
        .get_credential(&credential_id)
        .expect("credential should exist");

    assert_eq!(cred.learner_id, learner);
    assert_eq!(cred.course_id, course_id);
    assert_eq!(cred.issuer_id, ISSUER);
}

#[test]
fn authorized_revoke_succeeds() {
    let mut contract = new_contract();

    let credential_id = contract
        .issue_credential(ISSUER, LEARNER, COURSE_ID, None)
        .unwrap();

    assert!(
        contract.is_valid(&credential_id),
        "credential should be valid before revocation"
    );

    contract
        .revoke_credential(ISSUER, &credential_id)
        .expect("issuer should be able to revoke");

    assert!(
        !contract.is_valid(&credential_id),
        "credential should be revoked after revocation"
    );

    let cred = contract
        .get_credential(&credential_id)
        .expect("credential should still exist");
    assert!(cred.revoked, "revoked flag should be true");
}

#[test]
fn unauthorized_revoke_fails() {
    let mut contract = new_contract();

    let credential_id = contract
        .issue_credential(ISSUER, LEARNER, COURSE_ID, None)
        .unwrap();

    let result = contract.revoke_credential(NON_ISSUER, &credential_id);
    assert!(
        result.is_err(),
        "non-issuer should not be able to revoke credentials"
    );
    assert_eq!(result.unwrap_err(), ContractError::NotIssuer);
}

#[test]
fn revoked_credential_no_longer_valid() {
    let mut contract = new_contract();

    let credential_id = contract
        .issue_credential(ISSUER, LEARNER, COURSE_ID, None)
        .unwrap();

    assert!(
        contract.is_valid(&credential_id),
        "credential should be valid before revocation"
    );

    contract
        .revoke_credential(ISSUER, &credential_id)
        .unwrap();

    assert_eq!(
        contract.get_status(&credential_id),
        CredentialStatus::Revoked,
        "status should be Revoked after revocation"
    );
    assert!(
        !contract.is_valid(&credential_id),
        "credential should no longer be valid"
    );
}

#[test]
fn unknown_credential_returns_not_found() {
    let contract = new_contract();

    let unknown_id = "cred-99999";
    assert!(
        !contract.is_valid(unknown_id),
        "unknown credential should not be valid"
    );
    assert_eq!(
        contract.get_status(unknown_id),
        CredentialStatus::NotFound,
        "status should be NotFound for unknown credential"
    );
    assert!(
        contract.get_credential(unknown_id).is_none(),
        "get_credential should return None for unknown credential"
    );
}

#[test]
fn revoke_nonexistent_credential_fails() {
    let mut contract = new_contract();

    let result = contract.revoke_credential(ISSUER, "cred-nonexistent");
    assert!(
        result.is_err(),
        "revoking nonexistent credential should fail"
    );
    assert_eq!(result.unwrap_err(), ContractError::NotFound);
}

#[test]
fn revoke_already_revoked_credential_fails() {
    let mut contract = new_contract();

    let credential_id = contract
        .issue_credential(ISSUER, LEARNER, COURSE_ID, None)
        .unwrap();

    contract
        .revoke_credential(ISSUER, &credential_id)
        .unwrap();

    let result = contract.revoke_credential(ISSUER, &credential_id);
    assert!(
        result.is_err(),
        "revoking already revoked credential should fail"
    );
    assert_eq!(result.unwrap_err(), ContractError::AlreadyRevoked);
}

#[test]
fn issuer_authority_transfer() {
    let mut contract = new_contract();

    let new_issuer = "new-issuer.testnet";

    // Current issuer can transfer authority
    contract
        .transfer_issuer(ISSUER, new_issuer)
        .expect("issuer should be able to transfer authority");
    assert_eq!(contract.get_issuer(), new_issuer);

    // New issuer can now issue
    let credential_id = contract
        .issue_credential(new_issuer, LEARNER, COURSE_ID, None)
        .unwrap();
    assert!(!credential_id.is_empty(), "new issuer should be able to issue");

    // Old issuer can no longer issue
    let result = contract.issue_credential(ISSUER, LEARNER, COURSE_ID, None);
    assert!(
        result.is_err(),
        "old issuer should not be able to issue after transfer"
    );
    assert_eq!(result.unwrap_err(), ContractError::NotIssuer);

    // Non-issuer cannot transfer authority
    let result = contract.transfer_issuer(NON_ISSUER, LEARNER);
    assert!(
        result.is_err(),
        "non-issuer should not be able to transfer issuer authority"
    );
    assert_eq!(result.unwrap_err(), ContractError::NotIssuer);
}

#[test]
fn transfer_to_empty_issuer_rejected() {
    let mut contract = new_contract();

    let result = contract.transfer_issuer(ISSUER, "");
    assert!(
        result.is_err(),
        "empty new issuer ID should be rejected"
    );
    assert_eq!(result.unwrap_err(), ContractError::InvalidNewIssuer);
}

#[test]
fn learner_credentials_pagination() {
    let mut contract = new_contract();

    // Issue 5 credentials to the same learner
    let mut ids = Vec::new();
    for i in 0..5 {
        let id = contract
            .issue_credential(ISSUER, LEARNER, &format!("course-{}", i), None)
            .unwrap();
        ids.push(id);
    }

    // Get all credentials for the learner
    let all = contract.get_learner_credentials(LEARNER, None, None);
    assert_eq!(all.len(), 5, "learner should have 5 credentials");
    let mut sorted_ids = ids.clone();
    sorted_ids.sort();
    assert_eq!(all, sorted_ids, "should return all IDs sorted");

    // Pagination: first 2
    let first_two = contract.get_learner_credentials(LEARNER, Some(0), Some(2));
    assert_eq!(first_two.len(), 2, "should return first 2 credentials");

    // Pagination: skip first 2, get next 2
    let next_two = contract.get_learner_credentials(LEARNER, Some(2), Some(2));
    assert_eq!(next_two.len(), 2, "should return next 2 credentials");

    // Learner with no credentials
    let none = contract.get_learner_credentials(NON_ISSUER, None, None);
    assert!(
        none.is_empty(),
        "non-issuer should have no credentials"
    );
}

#[test]
fn multiple_learners_can_have_credentials() {
    let mut contract = new_contract();

    let id1 = contract
        .issue_credential(ISSUER, LEARNER, COURSE_ID, None)
        .unwrap();
    let id2 = contract
        .issue_credential(ISSUER, NON_ISSUER, COURSE_ID, None)
        .unwrap();

    let learner_creds = contract.get_learner_credentials(LEARNER, None, None);
    assert_eq!(learner_creds.len(), 1, "learner should have 1 credential");
    assert_eq!(learner_creds[0], id1);

    let bob_creds = contract.get_learner_credentials(NON_ISSUER, None, None);
    assert_eq!(bob_creds.len(), 1, "bob should have 1 credential");
    assert_eq!(bob_creds[0], id2);
}

#[test]
fn metadata_hash_is_optional() {
    let mut contract = new_contract();

    // Issue without metadata hash
    let id1 = contract
        .issue_credential(ISSUER, LEARNER, COURSE_ID, None)
        .unwrap();
    let cred1 = contract
        .get_credential(&id1)
        .expect("credential should exist");
    assert!(
        cred1.metadata_hash.is_none(),
        "metadata_hash should be None"
    );

    // Issue with metadata hash
    let id2 = contract
        .issue_credential(
            ISSUER,
            LEARNER,
            COURSE_ID,
            Some(METADATA_HASH),
        )
        .unwrap();
    let cred2 = contract.get_credential(&id2).expect("credential should exist");
    assert_eq!(
        cred2.metadata_hash,
        Some(METADATA_HASH.to_string()),
        "metadata_hash should match"
    );
}

#[test]
fn credential_count_and_revoked_count() {
    let mut contract = new_contract();

    assert_eq!(contract.credential_count(), 0);
    assert_eq!(contract.revoked_count(), 0);

    contract
        .issue_credential(ISSUER, LEARNER, COURSE_ID, None)
        .unwrap();
    contract
        .issue_credential(ISSUER, LEARNER, COURSE_ID, None)
        .unwrap();

    assert_eq!(contract.credential_count(), 2);
    assert_eq!(contract.revoked_count(), 0);

    let ids: Vec<String> = contract
        .get_learner_credentials(LEARNER, None, None)
        .iter()
        .cloned()
        .collect();
    contract.revoke_credential(ISSUER, &ids[0]).unwrap();

    assert_eq!(contract.credential_count(), 2);
    assert_eq!(contract.revoked_count(), 1);
}
