//! Web3Edu NEAR — Credential Registry Smart Contract
//!
//! A minimal, secure credential registry for on-chain learning credentials.
//!
//! This is a self-contained Rust implementation using only the standard library.
//! For NEAR testnet deployment, this logic would be ported to `near-sdk`:
//! - `Contract` → `#[near_bindgen]` struct with `near_sdk::collections`
//! - Caller ID → `near_sdk::env::predecessor_account_id()`
//! - Timestamp → `near_sdk::env::block_timestamp()`
//! - Serialization → `near_sdk::borsh`

use std::collections::{BTreeSet, HashMap};

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
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Credential {
    pub credential_id: String,
    pub learner_id: String,
    pub course_id: String,
    pub issuer_id: String,
    pub issued_at: u64,
    pub metadata_hash: Option<String>,
    pub revoked: bool,
}

/// Public verification status of a credential.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum CredentialStatus {
    Valid,
    Revoked,
    NotFound,
}

/// Errors returned by contract methods.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum ContractError {
    AlreadyInitialized,
    InvalidIssuer,
    NotIssuer,
    NotFound,
    AlreadyRevoked,
    InvalidLearner,
    InvalidCourse,
    InvalidNewIssuer,
}

impl std::fmt::Display for ContractError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            ContractError::AlreadyInitialized => write!(f, "Contract already initialized"),
            ContractError::InvalidIssuer => write!(f, "Invalid issuer account ID"),
            ContractError::NotIssuer => {
                write!(f, "Only the authorized issuer can perform this action")
            }
            ContractError::NotFound => write!(f, "Credential not found"),
            ContractError::AlreadyRevoked => {
                write!(f, "Credential is already revoked")
            }
            ContractError::InvalidLearner => write!(f, "Learner ID must not be empty"),
            ContractError::InvalidCourse => write!(f, "Course ID must not be empty"),
            ContractError::InvalidNewIssuer => {
                write!(f, "New issuer ID must not be empty")
            }
        }
    }
}

impl std::error::Error for ContractError {}

/// The credential registry contract.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Contract {
    /// Authorized issuer account. Only this account can issue or revoke.
    issuer: String,
    /// credential_id -> Credential
    credentials: HashMap<String, Credential>,
    /// learner_id -> set of credential_ids issued to that learner
    learner_credentials: HashMap<String, BTreeSet<String>>,
    /// Monotonic counter for generating unique credential IDs.
    next_credential_id: u64,
}

impl Default for Contract {
    fn default() -> Self {
        Self {
            issuer: String::new(),
            credentials: HashMap::new(),
            learner_credentials: HashMap::new(),
            next_credential_id: 0,
        }
    }
}

impl Contract {
    /// Initialize the contract with an authorized issuer account.
    /// Can only be called once (on deployment).
    pub fn new(issuer_id: &str) -> Result<Self, ContractError> {
        if issuer_id.is_empty() {
            return Err(ContractError::InvalidIssuer);
        }
        Ok(Self {
            issuer: issuer_id.to_string(),
            credentials: HashMap::new(),
            learner_credentials: HashMap::new(),
            next_credential_id: 0,
        })
    }

    /// Check if the contract has been initialized (issuer is non-empty).
    pub fn is_initialized(&self) -> bool {
        !self.issuer.is_empty()
    }

    /// Issue a new credential.
    ///
    /// Only the authorized issuer can call this method.
    ///
    /// # Arguments
    /// * `caller` - The account ID of the caller (must be the issuer)
    /// * `learner_id` - NEAR account ID of the learner receiving the credential
    /// * `course_id` - Course or skill identifier (free-form string)
    /// * `metadata_hash` - Optional off-chain commitment (e.g. IPFS CID hash)
    ///
    /// Returns the auto-generated credential ID.
    pub fn issue_credential(
        &mut self,
        caller: &str,
        learner_id: &str,
        course_id: &str,
        metadata_hash: Option<&str>,
    ) -> Result<String, ContractError> {
        if caller != self.issuer {
            return Err(ContractError::NotIssuer);
        }
        if learner_id.is_empty() {
            return Err(ContractError::InvalidLearner);
        }
        if course_id.is_empty() {
            return Err(ContractError::InvalidCourse);
        }

        let credential_id = format!("cred-{}", self.next_credential_id);
        self.next_credential_id += 1;

        let now = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap_or_default()
            .as_nanos() as u64;

        let credential = Credential {
            credential_id: credential_id.clone(),
            learner_id: learner_id.to_string(),
            course_id: course_id.to_string(),
            issuer_id: self.issuer.clone(),
            issued_at: now,
            metadata_hash: metadata_hash.map(|s| s.to_string()),
            revoked: false,
        };

        self.credentials
            .insert(credential_id.clone(), credential);

        // Track the credential under the learner's set
        self.learner_credentials
            .entry(learner_id.to_string())
            .or_insert_with(BTreeSet::new)
            .insert(credential_id.clone());

        Ok(credential_id)
    }

    /// Retrieve a credential by its ID.
    ///
    /// Returns the full `Credential` record if found, `None` otherwise.
    pub fn get_credential(&self, credential_id: &str) -> Option<&Credential> {
        self.credentials.get(credential_id)
    }

    /// Check whether a credential exists and is currently valid (not revoked).
    pub fn is_valid(&self, credential_id: &str) -> bool {
        match self.credentials.get(credential_id) {
            Some(c) => !c.revoked,
            None => false,
        }
    }

    /// Get the verification status of a credential.
    ///
    /// Returns `Valid`, `Revoked`, or `NotFound`.
    pub fn get_status(&self, credential_id: &str) -> CredentialStatus {
        match self.credentials.get(credential_id) {
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
        learner_id: &str,
        from_index: Option<u64>,
        limit: Option<u64>,
    ) -> Vec<String> {
        let learner_creds = match self.learner_credentials.get(learner_id) {
            Some(s) => s,
            None => return Vec::new(),
        };

        let mut all: Vec<String> = learner_creds.iter().cloned().collect();
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
    /// Returns `NotIssuer` if called by a non-issuer,
    /// `NotFound` if the credential doesn't exist,
    /// `AlreadyRevoked` if the credential is already revoked.
    pub fn revoke_credential(
        &mut self,
        caller: &str,
        credential_id: &str,
    ) -> Result<(), ContractError> {
        if caller != self.issuer {
            return Err(ContractError::NotIssuer);
        }

        let credential = self
            .credentials
            .get_mut(credential_id)
            .ok_or(ContractError::NotFound)?;

        if credential.revoked {
            return Err(ContractError::AlreadyRevoked);
        }

        credential.revoked = true;
        Ok(())
    }

    /// Get the current authorized issuer account ID.
    pub fn get_issuer(&self) -> &str {
        &self.issuer
    }

    /// Transfer issuer authority to a new account.
    ///
    /// Only the current issuer can call this method.
    ///
    /// # Arguments
    /// * `caller` - The account ID of the caller (must be the current issuer)
    /// * `new_issuer_id` - NEAR account ID of the new issuer
    ///
    /// # Errors
    /// Returns `NotIssuer` if called by a non-issuer,
    /// `InvalidNewIssuer` if new_issuer_id is empty.
    pub fn transfer_issuer(
        &mut self,
        caller: &str,
        new_issuer_id: &str,
    ) -> Result<(), ContractError> {
        if caller != self.issuer {
            return Err(ContractError::NotIssuer);
        }
        if new_issuer_id.is_empty() {
            return Err(ContractError::InvalidNewIssuer);
        }
        self.issuer = new_issuer_id.to_string();
        Ok(())
    }

    /// Get the total number of credentials issued.
    pub fn credential_count(&self) -> u64 {
        self.credentials.len() as u64
    }

    /// Get the total number of revoked credentials.
    pub fn revoked_count(&self) -> u64 {
        self.credentials
            .values()
            .filter(|c| c.revoked)
            .count() as u64
    }
}
