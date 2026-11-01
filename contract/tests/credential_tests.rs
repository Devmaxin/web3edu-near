use near_sdk::test_utils::VMContextBuilder;
use near_sdk::{testing_env, AccountId};
use std::panic::AssertUnwindSafe;
use web3edu_near_contract::{Contract, CredentialStatus};

const ISSUER: &str = "issuer.testnet";
const LEARNER: &str = "learner.testnet";
const NON_ISSUER: &str = "non-issuer.testnet";
const COURSE_ID: &str = "web3-101";
const METADATA_HASH: &str = "QmXoypizjW3WknFiJnKLwHCnL72vedxjQkDDP1mXWo6uco";

fn issuer_account() -> AccountId {
    ISSUER.parse().unwrap()
}

fn learner_account() -> AccountId {
    LEARNER.parse().unwrap()
}

fn non_issuer_account() -> AccountId {
    NON_ISSUER.parse().unwrap()
}

/// Setup context with issuer as predecessor and set current block timestamp.
/// Requires `near-sdk` `unit-testing` feature (provides mock blockchain env
/// via `near-vm-runner`). Needs `dlltool.exe`/MinGW to compile on Windows.
fn setup_context(predecessor: AccountId) {
    let mut builder = VMContextBuilder::new();
    builder
        .current_account_id(issuer_account())
        .signer_account_id(predecessor.clone())
        .predecessor_account_id(predecessor)
        .block_timestamp(1_000_000_000_000_000);
    testing_env!(builder.build());
}

fn new_contract_for_test() -> Contract {
    setup_context(issuer_account());
    Contract::new(issuer_account())
}

#[test]
fn contract_initializes_with_issuer() {
    setup_context(issuer_account());
    let contract = Contract::new(issuer_account());
    assert_eq!(contract.get_issuer(), issuer_account());
}

#[test]
fn authorized_issue_succeeds() {
    setup_context(issuer_account());
    let mut contract = Contract::new(issuer_account());

    // issue_credential returns String directly — no .unwrap() needed
    let credential_id = contract.issue_credential(
        learner_account(),
        COURSE_ID.to_string(),
        Some(METADATA_HASH.to_string()),
    );

    assert!(!credential_id.is_empty(), "credential_id should not be empty");
    assert!(
        credential_id.starts_with("cred-"),
        "credential_id should start with 'cred-'"
    );

    let cred = contract.get_credential(credential_id.clone()).expect("credential should exist");
    assert_eq!(cred.learner_id, learner_account());
    assert_eq!(cred.course_id, COURSE_ID);
    assert_eq!(cred.issuer_id, issuer_account());
    assert_eq!(cred.metadata_hash, Some(METADATA_HASH.to_string()));
    assert!(!cred.revoked, "credential should not be revoked initially");
    assert_eq!(cred.issued_at, 1_000_000_000_000_000);
}

#[test]
fn unauthorized_issue_fails() {
    setup_context(non_issuer_account());
    let mut contract = Contract::new(issuer_account());

    // issue_credential returns String, panics on unauthorized caller
    let result = AssertUnwindSafe(|| {
        contract.issue_credential(
            learner_account(),
            COURSE_ID.to_string(),
            Some(METADATA_HASH.to_string()),
        )
    }).catch_unwind();

    assert!(
        result.is_err(),
        "non-issuer should not be able to issue credentials"
    );
}

#[test]
fn empty_course_id_rejected() {
    setup_context(issuer_account());
    let mut contract = Contract::new(issuer_account());

    // Empty course_id string panics inside the contract assert
    let result = AssertUnwindSafe(|| {
        contract.issue_credential(learner_account(), "".to_string(), None)
    }).catch_unwind();

    assert!(
        result.is_err(),
        "empty course_id should be rejected"
    );
}

#[test]
fn unique_credential_ids() {
    setup_context(issuer_account());
    let mut contract = Contract::new(issuer_account());

    let id1 = contract.issue_credential(learner_account(), COURSE_ID.to_string(), None);
    let id2 = contract.issue_credential(learner_account(), COURSE_ID.to_string(), None);

    assert_ne!(
        id1, id2,
        "each credential should get a unique ID"
    );

    let cred1 = contract.get_credential(id1.clone()).expect("credential 1 should exist");
    let cred2 = contract.get_credential(id2.clone()).expect("credential 2 should exist");
    assert_ne!(cred1.credential_id, cred2.credential_id, "IDs must differ");
}

#[test]
fn credential_can_be_read() {
    let mut contract = new_contract_for_test();

    let credential_id = contract.issue_credential(learner_account(), COURSE_ID.to_string(), None);

    let retrieved = contract.get_credential(credential_id.clone());
    assert!(
        retrieved.is_some(),
        "credential should be retrievable by ID"
    );
    let cred = retrieved.expect("credential should exist");
    assert_eq!(cred.credential_id, credential_id);
    assert_eq!(cred.learner_id, learner_account());
    assert_eq!(cred.course_id, COURSE_ID);
}

#[test]
fn correct_learner_and_course_data_stored() {
    let mut contract = new_contract_for_test();

    let course_id = "near-smart-contracts-201";
    let learner = learner_account();

    let credential_id = contract.issue_credential(learner.clone(), course_id.to_string(), None);
    let cred = contract
        .get_credential(credential_id)
        .expect("credential should exist");

    assert_eq!(cred.learner_id, learner);
    assert_eq!(cred.course_id, course_id);
    assert_eq!(cred.issuer_id, issuer_account());
}

#[test]
fn authorized_revoke_succeeds() {
    let mut contract = new_contract_for_test();

    let credential_id = contract.issue_credential(learner_account(), COURSE_ID.to_string(), None);

    assert!(
        contract.is_valid(credential_id.clone()),
        "credential should be valid before revocation"
    );

    // revoke_credential returns ()
    contract.revoke_credential(credential_id.clone());

    assert!(
        !contract.is_valid(credential_id.clone()),
        "credential should be revoked after revocation"
    );

    let cred = contract
        .get_credential(&credential_id)
        .expect("credential should still exist");
    assert!(cred.revoked, "revoked flag should be true");
}

#[test]
fn revoke_already_revoked_credential_fails() {
    let mut contract = new_contract_for_test();

    let credential_id = contract.issue_credential(learner_account(), COURSE_ID.to_string(), None);
    contract.revoke_credential(credential_id.clone());

    // revoke_credential returns () and panics on already-revoked credential
    let result = AssertUnwindSafe(|| {
        contract.revoke_credential(credential_id.clone())
    }).catch_unwind();

    assert!(
        result.is_err(),
        "revoking an already-revoked credential should fail"
    );
}

#[test]
fn revoke_nonexistent_credential_fails() {
    let mut contract = new_contract_for_test();

    // revoke_credential panics on nonexistent credential
    let result = AssertUnwindSafe(|| {
        contract.revoke_credential("cred-99999".to_string())
    }).catch_unwind();

    assert!(
        result.is_err(),
        "revoking a nonexistent credential should fail"
    );
}

#[test]
fn unauthorized_revoke_fails() {
    setup_context(non_issuer_account());
    let mut contract = Contract::new(issuer_account());

    // Issuer issues a credential first
    setup_context(issuer_account());
    let credential_id = contract.issue_credential(
        learner_account(),
        COURSE_ID.to_string(),
        None,
    );

    // Non-issuer tries to revoke
    setup_context(non_issuer_account());
    let result = AssertUnwindSafe(|| {
        contract.revoke_credential(credential_id.clone())
    }).catch_unwind();

    assert!(
        result.is_err(),
        "non-issuer should not be able to revoke credentials"
    );
}

#[test]
fn revoked_credential_no_longer_valid() {
    let mut contract = new_contract_for_test();

    let credential_id = contract.issue_credential(learner_account(), COURSE_ID.to_string(), None);

    assert!(
        contract.is_valid(credential_id.clone()),
        "credential should be valid before revocation"
    );

    contract.revoke_credential(credential_id.clone());

    // CredentialStatus implements PartialEq/Eq, so assert_eq! works
    assert_eq!(
        contract.get_status(credential_id.clone()),
        CredentialStatus::Revoked,
        "status should be Revoked after revocation"
    );
    assert!(
        !contract.is_valid(credential_id.clone()),
        "credential should no longer be valid"
    );
}

#[test]
fn unknown_credential_returns_not_found() {
    setup_context(issuer_account());
    let contract = Contract::new(issuer_account());

    let unknown_id = "cred-99999".to_string();
    assert!(
        !contract.is_valid(unknown_id.clone()),
        "unknown credential should not be valid"
    );
    assert_eq!(
        contract.get_status(unknown_id.clone()),
        CredentialStatus::NotFound,
        "status should be NotFound for unknown credential"
    );
    assert!(
        contract.get_credential(unknown_id).is_none(),
        "get_credential should return None for unknown credential"
    );
}

#[test]
fn issuer_authority_transfer() {
    setup_context(issuer_account());
    let mut contract = Contract::new(issuer_account());

    let new_issuer: AccountId = "new-issuer.testnet".parse().unwrap();

    // Current issuer can transfer authority — transfer_issuer returns ()
    contract.transfer_issuer(new_issuer.clone());
    assert_eq!(contract.get_issuer(), new_issuer);

    // New issuer can now issue
    setup_context(new_issuer.clone());
    let credential_id = contract.issue_credential(
        learner_account(),
        COURSE_ID.to_string(),
        None,
    );
    assert!(!credential_id.is_empty(), "new issuer should be able to issue");

    // Old issuer can no longer issue
    setup_context(issuer_account());
    let result = AssertUnwindSafe(|| {
        contract.issue_credential(learner_account(), COURSE_ID.to_string(), None)
    }).catch_unwind();
    assert!(
        result.is_err(),
        "old issuer should not be able to issue after transfer"
    );

    // Non-issuer cannot transfer authority — transfer_issuer returns () and panics
    setup_context(non_issuer_account());
    let result = AssertUnwindSafe(|| {
        contract.transfer_issuer(learner_account())
    }).catch_unwind();
    assert!(
        result.is_err(),
        "non-issuer should not be able to transfer issuer authority"
    );
}

#[test]
fn unauthorized_transfer_issuer_fails() {
    setup_context(non_issuer_account());
    let mut contract = Contract::new(issuer_account());

    let new_issuer: AccountId = "new-issuer.testnet".parse().unwrap();

    // transfer_issuer returns () and panics when called by non-issuer
    let result = AssertUnwindSafe(|| {
        contract.transfer_issuer(new_issuer.clone())
    }).catch_unwind();

    assert!(
        result.is_err(),
        "non-issuer should not be able to transfer issuer authority"
    );
}

#[test]
fn learner_credentials_pagination() {
    let mut contract = new_contract_for_test();

    // Issue 5 credentials to the same learner
    let mut ids = Vec::new();
    for i in 0..5 {
        let id = contract.issue_credential(learner_account(), format!("course-{}", i), None);
        ids.push(id);
    }

    // Get all credentials for the learner
    let all = contract.get_learner_credentials(learner_account(), None, None);
    assert_eq!(all.len(), 5, "learner should have 5 credentials");
    let mut sorted_ids = ids.clone();
    sorted_ids.sort();
    assert_eq!(all, sorted_ids, "should return all IDs sorted");

    // Pagination: first 2
    let first_two = contract.get_learner_credentials(learner_account(), Some(0), Some(2));
    assert_eq!(first_two.len(), 2, "should return first 2 credentials");

    // Pagination: skip first 2, get next 2
    let next_two = contract.get_learner_credentials(learner_account(), Some(2), Some(2));
    assert_eq!(next_two.len(), 2, "should return next 2 credentials");

    // Learner with no credentials
    let none = contract.get_learner_credentials(non_issuer_account(), None, None);
    assert!(
        none.is_empty(),
        "non-issuer should have no credentials"
    );
}

#[test]
fn multiple_learners_can_have_credentials() {
    let mut contract = new_contract_for_test();

    let id1 = contract.issue_credential(issuer_account(), COURSE_ID.to_string(), None);
    let id2 = contract.issue_credential(non_issuer_account(), COURSE_ID.to_string(), None);

    let learner_creds = contract.get_learner_credentials(learner_account(), None, None);
    assert_eq!(learner_creds.len(), 1, "learner should have 1 credential");
    assert_eq!(learner_creds[0], id1);

    let bob_creds = contract.get_learner_credentials(non_issuer_account(), None, None);
    assert_eq!(bob_creds.len(), 1, "bob should have 1 credential");
    assert_eq!(bob_creds[0], id2);
}

#[test]
fn metadata_hash_is_optional() {
    let mut contract = new_contract_for_test();

    // Issue without metadata hash
    let id1 = contract.issue_credential(issuer_account(), COURSE_ID.to_string(), None);
    let cred1 = contract.get_credential(id1.clone()).expect("credential should exist");
    assert!(
        cred1.metadata_hash.is_none(),
        "metadata_hash should be None"
    );

    // Issue with metadata hash
    let id2 = contract.issue_credential(
        issuer_account(),
        COURSE_ID.to_string(),
        Some(METADATA_HASH.to_string()),
    );
    let cred2 = contract.get_credential(id2.clone()).expect("credential should exist");
    assert_eq!(
        cred2.metadata_hash,
        Some(METADATA_HASH.to_string()),
        "metadata_hash should match"
    );
}
