//! Web3Edu NEAR — Credential Registry Smart Contract
//!
//! A minimal, secure credential registry for on-chain learning credentials,
//! implemented using near-sdk and deployed to NEAR testnet.
//!
//! Only minimal, non-sensitive data is stored on-chain:
//! - credential_id: unique identifier (auto-generated)
//! - learner_id: NEAR account ID of the learner
//! - course_id: course or skill identifier (string)
//! - issuer_id: NEAR account ID of the issuing authority
//! - issued_at: block timestamp when issued
//! - metadata_hash: optional off-chain commitment (e.g. IPFS CID hash)
//! - revoked: whether the credential has been revoked
//!
//! No personally identifying information (names, emails, grades, passwords,
//! private learning records, or other personal data) is stored on-chain.

use near_sdk::collections::{LookupMap, UnorderedMap, UnorderedSet};
use near_sdk::{env, near, AccountId, BorshStorageKey, PanicOnDefault};
use near_sdk::borsh::{BorshDeserialize, BorshSerialize};

/// A single on-chain credential record.
///
/// Only minimal, non-sensitive data is stored on-chain:
/// - credential_id: unique identifier (auto-generated)
/// - learner_id: NEAR account ID of the learner
/// - course_id: course or skill identifier (string)
/// - issuer_id: NEAR account ID of the issuing authority
/// - issued_at: block timestamp when issued
/// - metadata_hash: optional off-chain commitment (e.g. IPFS CID hash)
/// - revoked: whether the credential has been revoked
///
/// No personally identifying information (names, emails, grades, passwords,
/// private learning records, or other personal data) is stored on-chain.
#[derive(BorshSerialize, BorshDeserialize, Clone, PartialEq, Eq, Debug, serde::Serialize, serde::Deserialize)]
pub struct Credential {
    pub credential_id: String,
    pub learner_id: AccountId,
    pub course_id: String,
    pub issuer_id: AccountId,
    pub issued_at: u64,
    pub metadata_hash: Option<String>,
    pub revoked: bool,
}

/// Public verification status of a credential.
#[derive(BorshSerialize, BorshDeserialize, Clone, Debug, serde::Serialize, serde::Deserialize)]
pub enum CredentialStatus {
    Valid,
    Revoked,
    NotFound,
}

#[derive(BorshSerialize, BorshStorageKey)]
enum StorageKey {
    Credentials,
    LearnerCredentials,
    /// Inner prefix for per-learner credential sets. The account_id field
    /// ensures each learner gets a unique storage prefix.
    LearnerCredentialsInner { account_id: AccountId },
}

#[near(contract_state)]
#[derive(PanicOnDefault)]
pub struct Contract {
    /// Authorized issuer account. Only this account can issue or revoke.
    issuer: AccountId,
    /// credential_id -> Credential
    credentials: LookupMap<String, Credential>,
    /// learner_id -> set of credential_ids issued to that learner
    learner_credentials: UnorderedMap<AccountId, UnorderedSet<String>>,
    /// Monotonic counter for generating unique credential IDs.
    next_credential_id: u64,
}

#[near]
impl Contract {
    /// Initialize the contract with an authorized issuer account.
    /// Can only be called once (on deployment).
    #[init]
    pub fn new(issuer_id: AccountId) -> Self {
        assert!(!env::state_exists(), "Contract already initialized");
        assert!(!issuer_id.as_str().is_empty(), "ERR_INVALID_ISSUER");
        Self {
            issuer: issuer_id,
            credentials: LookupMap::new(StorageKey::Credentials),
            learner_credentials: UnorderedMap::new(StorageKey::LearnerCredentials),
            next_credential_id: 0,
        }
    }

    /// Issue a new credential.
    ///
    /// Only the authorized issuer can call this method.
    ///
    /// # Arguments
    /// * `learner_id` - NEAR account ID of the learner receiving the credential
    /// * `course_id` - Course or skill identifier (free-form string)
    /// * `metadata_hash` - Optional off-chain commitment (e.g. IPFS CID hash)
    ///
    /// Returns the auto-generated credential ID.
    ///
    /// # Errors
    /// Panics if called by a non-issuer, or if learner_id/course_id is empty.
    pub fn issue_credential(
        &mut self,
        learner_id: AccountId,
        course_id: String,
        metadata_hash: Option<String>,
    ) -> String {
        assert_eq!(
            env::predecessor_account_id(),
            self.issuer,
            "ERR_NOT_ISSUER: only the authorized issuer can issue credentials"
        );
        assert!(
            !learner_id.as_str().is_empty(),
            "ERR_INVALID_LEARNER: learner_id must not be empty"
        );
        assert!(
            !course_id.is_empty(),
            "ERR_INVALID_COURSE: course_id must not be empty"
        );

        let credential_id = format!("cred-{}", self.next_credential_id);
        self.next_credential_id += 1;

        let credential = Credential {
            credential_id: credential_id.clone(),
            learner_id: learner_id.clone(),
            course_id,
            issuer_id: self.issuer.clone(),
            issued_at: env::block_timestamp(),
            metadata_hash,
            revoked: false,
        };

        self.credentials.insert(&credential_id, &credential);

        // Track the credential under the learner's set
        let mut learner_creds = self
            .learner_credentials
            .get(&learner_id)
            .unwrap_or_else(|| {
                UnorderedSet::new(StorageKey::LearnerCredentialsInner {
                    account_id: learner_id.clone(),
                })
            });
        learner_creds.insert(&credential_id);
        self.learner_credentials
            .insert(&learner_id, &learner_creds);

        credential_id
    }

    /// Retrieve a credential by its ID.
    ///
    /// Returns the full `Credential` record if found, `None` otherwise.
    pub fn get_credential(&self, credential_id: String) -> Option<Credential> {
        self.credentials.get(&credential_id)
    }

    /// Check whether a credential exists and is currently valid (not revoked).
    pub fn is_valid(&self, credential_id: String) -> bool {
        match self.credentials.get(&credential_id) {
            Some(c) => !c.revoked,
            None => false,
        }
    }

    /// Get the verification status of a credential.
    ///
    /// Returns `Valid`, `Revoked`, or `NotFound`.
    pub fn get_status(&self, credential_id: String) -> CredentialStatus {
        match self.credentials.get(&credential_id) {
            Some(c) if c.revoked => CredentialStatus::Revoked,
            Some(_) => CredentialStatus::Valid,
            None => CredentialStatus::NotFound,
        }
    }

    /// List credential IDs for a learner, with pagination.
    ///
    /// # Arguments
    /// * `learner_id` - NEAR account ID of the learner
    /// * `from_index` - Optional starting index (0-based) for pagination
    /// * `limit` - Optional maximum number of IDs to return
    ///
    /// Returns a sorted Vec of credential IDs belonging to the learner.
    /// Returns an empty vector if the learner has no credentials.
    pub fn get_learner_credentials(
        &self,
        learner_id: AccountId,
        from_index: Option<u64>,
        limit: Option<u64>,
    ) -> Vec<String> {
        let learner_creds = match self.learner_credentials.get(&learner_id) {
            Some(s) => s,
            None => return Vec::new(),
        };

        let mut all: Vec<String> = learner_creds.iter().collect::<Vec<String>>();
        all.sort();

        let start = from_index.unwrap_or(0) as usize;
        let end = (start
            + limit
                .map(|l| l as usize)
                .unwrap_or(usize::MAX))
            .min(all.len());

        if start >= all.len() {
            Vec::new()
        } else {
            all[start..end].to_vec()
        }
    }

    /// Revoke a credential permanently.
    ///
    /// Only the authorized issuer can call this method.
    /// Revocation is permanent for that credential ID.
    ///
    /// # Errors
    /// Panics if called by a non-issuer, if the credential doesn't exist,
    /// or if the credential is already revoked.
    pub fn revoke_credential(&mut self, credential_id: String) {
        assert_eq!(
            env::predecessor_account_id(),
            self.issuer,
            "ERR_NOT_ISSUER: only the authorized issuer can revoke credentials"
        );

        let mut credential = self
            .credentials
            .get(&credential_id)
            .expect("ERR_NOT_FOUND: credential does not exist");

        assert!(
            !credential.revoked,
            "ERR_ALREADY_REVOKED: credential is already revoked"
        );

        credential.revoked = true;
        self.credentials.insert(&credential_id, &credential);
    }

    /// Get the current authorized issuer account ID.
    pub fn get_issuer(&self) -> AccountId {
        self.issuer.clone()
    }

    /// Transfer issuer authority to a new account.
    ///
    /// Only the current issuer can call this method.
    ///
    /// # Arguments
    /// * `new_issuer_id` - NEAR account ID of the new issuer
    ///
    /// # Errors
    /// Panics if called by a non-issuer, or if new_issuer_id is empty.
    pub fn transfer_issuer(&mut self, new_issuer_id: AccountId) {
        assert_eq!(
            env::predecessor_account_id(),
            self.issuer,
            "ERR_NOT_ISSUER: only the current issuer can transfer authority"
        );
        assert!(
            !new_issuer_id.as_str().is_empty(),
            "ERR_INVALID_ISSUER: new issuer ID must not be empty"
        );
        self.issuer = new_issuer_id;
    }
}
