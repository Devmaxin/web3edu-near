# Web3Edu — Web3 Education with Verifiable Credentials

**Learn Web3. Build your skills. Prove what you've achieved.**

Web3Edu is a structured Web3 education platform designed to help people move from being curious about blockchain technology to developing practical knowledge and skills.

Instead of navigating disconnected tutorials and learning resources, learners can follow structured learning paths, complete lessons, take quizzes, and earn certificates.

For the **NEAR HACKATHON: Build Beyond Boundaries**, Web3Edu was extended with a NEAR Protocol integration that makes educational achievements independently verifiable on-chain.

**Live Platform:** https://web3edunear.top

**Network:** NEAR Testnet

**Smart Contract:** `web3edu-near.testnet`

---

## Table of Contents

- [The Problem](#the-problem)
- [Our Solution](#our-solution)
- [NEAR Protocol Integration](#near-protocol-integration)
- [How It Works](#how-it-works)
- [Key Features](#key-features)
- [Technology Stack](#technology-stack)
- [Architecture](#architecture)
- [Getting Started](#getting-started)
- [NEAR Configuration](#near-configuration)
- [Credential Verification](#credential-verification)
- [Smart Contract](#smart-contract)
- [Hackathon Contribution](#hackathon-contribution)
- [Security Notes](#security-notes)
- [Future Improvements](#future-improvements)
- [Project Links](#project-links)

---

## The Problem

Web3 education is often fragmented.

Learners encounter scattered resources, disconnected tutorials, and limited guidance on what to learn next. Even after completing educational programmes, proving those achievements to other people or organizations can be difficult.

Traditional certificates also depend heavily on the issuing platform for verification.

Web3Edu aims to address these challenges by combining structured learning with blockchain-powered credential verification.

## Our Solution

Web3Edu provides a guided Web3 learning experience where learners can:

- Explore structured Web3 learning paths.
- Study lessons and educational materials.
- Complete quizzes and assessments.
- Earn certificates for eligible achievements.
- Connect a NEAR wallet.
- Have eligible educational credentials issued and verified on NEAR.

The platform combines a familiar web-based learning experience with blockchain infrastructure that makes credential verification more transparent.

## NEAR Protocol Integration

The NEAR integration introduces on-chain educational credentials to Web3Edu.

A custom smart contract deployed on NEAR Testnet records credential information associated with a learner's educational achievement.

The contract supports:

- Credential issuance by an authorized issuer.
- Credential retrieval.
- Credential validity checks.
- Credential status inspection.
- Retrieval of credentials associated with a learner.
- Credential revocation.
- Issuer management.

Rather than putting the complete certificate and personal information on-chain, Web3Edu stores a metadata hash that can be compared against the original certificate during verification.

This allows the platform to demonstrate independently verifiable educational achievements while keeping the certificate experience accessible through the Web3Edu application.

## How It Works

### 1. Learn

A learner registers, explores available learning content, and completes eligible courses.

### 2. Earn a Certificate

After meeting the required completion and assessment criteria, the platform issues a certificate.

### 3. Connect a NEAR Wallet

The learner connects a NEAR account to associate their educational achievements with their blockchain identity.

### 4. Credential Issuance

An eligible certificate enters the NEAR issuance queue.

An authorized issuer reviews pending requests, submits the corresponding transaction to the NEAR Testnet contract, and provides the transaction hash to Web3Edu for verification.

### 5. On-Chain Verification

Web3Edu retrieves the credential from the smart contract and compares its information against the original certificate.

The verification checks include:

- Learner identity.
- Course information.
- Certificate metadata hash.
- Authorized issuer.
- Credential validity.

### 6. Public Verification

A certificate can be checked through its public verification page.

The result distinguishes local certificate validity from successful NEAR on-chain verification.

## Key Features

### Learning Platform

- Structured Web3 learning content.
- Lessons and educational resources.
- Quizzes and assessments.
- Learning paths and progress tracking.
- Digital certificates.

### NEAR Integration

- NEAR wallet authentication.
- NEAR Testnet smart contract.
- On-chain credential issuance.
- Issuer-controlled issuance workflow.
- Credential lookup and status verification.
- Metadata hash verification.
- Public certificate verification.

## Technology Stack

| Layer | Technology |
|---|---|
| Backend | PHP |
| Database | MySQL / MariaDB |
| Frontend | HTML, CSS, JavaScript |
| Blockchain | NEAR Protocol |
| Smart Contract | Rust (near-sdk 5.30.0) |
| Wallet Integration | NEAR Connect |
| Blockchain Network | NEAR Testnet |

## Architecture

Web3Edu uses a hybrid architecture.

**Application Layer**

PHP handles user accounts, learning progress, certificates, eligibility checks, and issuance requests.

**Database Layer**

MySQL stores learner information, certificates, wallet associations, and credential issuance records.

**Blockchain Layer**

The NEAR smart contract records and exposes credential information for independent verification.

**Verification Layer**

The public verification endpoint retrieves the local certificate and associated NEAR issuance record, queries the smart contract, and checks the credential information against the certificate.

## Getting Started

### Requirements

- PHP 8.1 or later.
- MySQL or MariaDB.
- A web server supporting PHP.
- PHP extensions: PDO MySQL, cURL, sodium, bcmath, mbstring.
- A NEAR Testnet account for blockchain-related operations.

### 1. Clone the Repository

```bash
git clone https://github.com/Devmaxin/web3edu-near.git

cd web3edu-near
```

### 2. Configure the Database

Create a MySQL/MariaDB database and import the base schema:

```bash
mysql -u your_user -p your_database < database/web3edu_v2.sql
```

Then apply the NEAR credential issuance migration:

```bash
mysql -u your_user -p your_database < migrations/002-near-credential-issuance.sql
```

This migration creates the `near_credential_issuances` table used to track credential issuance requests and their on-chain status.

Additional migrations may exist in the `database/` and `migrations/` directories for other platform features. Apply them as needed for your deployment.

### 3. Configure Environment Variables

Configure the application in your local environment. Create a `.env` file in the project root (this file is gitignored and must never be committed):

```env
# Database
DB_HOST=localhost
DB_PORT=3306
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASS=your_database_password

# Site
SITE_URL=http://localhost:8000

# NEAR
NEAR_CONTRACT_ID=web3edu-near.testnet
NEAR_ISSUER_ADMIN_ID=your_admin_user_id
NEAR_NETWORK=testnet
NEAR_RPC_URL=https://test.rpc.fastnear.com
```

**Important:** `NEAR_ISSUER_ADMIN_ID` must be set to a valid administrator user ID from your `users` table. Without it, NEAR issuer operations are disabled by design.

Never commit your `.env` file, private keys, or other credentials to the repository.

### 4. Run the Application

Configure your PHP web server to serve the project directory.

For a local PHP development server:

```bash
php -S localhost:8000
```

Open:

```text
http://localhost:8000
```

**Note:** A complete local setup requires the base database schema, required migrations, web server configuration, and environment variables. The repository does not include a one-click installer; additional configuration is required before the application is fully operational.

### 5. Frontend Build (Optional)

The NEAR wallet connector is bundled with esbuild. The pre-built bundle exists at `frontend/js/wallet.bundle.js`. To rebuild it:

```bash
npm install
npm run build
```

This requires Node.js and esbuild.

## NEAR Configuration

The deployed NEAR Testnet contract is:

| Property | Value |
|---|---|
| Network | NEAR Testnet |
| Contract Account | `web3edu-near.testnet` |
| Contract Language | Rust |
| SDK | near-sdk 5.30.0 (legacy feature) |

The contract exposes methods for issuing, retrieving, validating, and revoking credentials.

The application uses NEAR RPC to retrieve contract data and verify submitted issuance transactions.

## Credential Verification

Web3Edu's public verification system checks both the original application certificate and its associated blockchain credential.

A successful verification confirms that:

1. The local certificate exists and is valid.
2. The corresponding NEAR credential exists.
3. The learner information matches.
4. The course information matches.
5. The metadata hash matches.
6. The issuer matches the expected contract issuer.

If the blockchain RPC is unavailable, the application distinguishes that situation from a confirmed invalid credential.

**Example verification:**

https://web3edunear.top/verify-near.php?id=WEB3-2026-000002

This is a demonstration certificate. Its verification result reflects the state of the deployed Testnet credential at the time of checking and is not a permanent guarantee of availability.

## Smart Contract

The NEAR smart contract source is located in the repository's `contract/` directory.

Its principal methods include:

| Method | Purpose |
|---|---|
| `issue_credential` | Issues an educational credential (issuer only) |
| `get_credential` | Retrieves a credential |
| `is_valid` | Checks credential validity |
| `get_status` | Retrieves credential status (Valid / Revoked / NotFound) |
| `get_learner_credentials` | Retrieves a learner's credentials |
| `revoke_credential` | Revokes a credential (issuer only) |
| `transfer_issuer` | Transfers issuer authority (issuer only) |
| `get_issuer` | Retrieves the current issuer |

The deployed contract is currently configured for NEAR Testnet. It is not presented as a Mainnet credential system.

## Hackathon Contribution

Web3Edu is an existing educational platform. The NEAR HACKATHON contribution focuses on integrating NEAR Protocol into its certificate and credential infrastructure.

The hackathon-specific development includes:

- A custom NEAR smart contract for educational credentials.
- NEAR wallet authentication using NEP-413 message signing.
- Linking application certificates to on-chain credentials.
- Automated credential issuance queueing.
- An authorized issuer workflow with local signing.
- Transaction verification through NEAR RPC.
- Public verification of certificates against on-chain records.
- Metadata hash comparison to detect mismatched certificate information.

This contribution demonstrates how an educational platform can use NEAR to make learning achievements independently verifiable.

## Security Notes

- The deployed demonstration uses NEAR Testnet, not Mainnet.
- Issuance is restricted to an authorized issuer.
- State-changing issuer requests require CSRF validation.
- Transaction hashes are checked before being associated with issuance requests.
- Credential verification compares on-chain information with the local certificate.
- Private keys and environment secrets must never be committed to the repository.
- The `.env` file is gitignored and must not be tracked.

## Future Improvements

Potential improvements include:

- Expanded NEAR wallet support.
- A more automated issuer transaction workflow.
- Additional credential standards and interoperability.
- Mainnet deployment after further testing.
- Integration with external educational institutions and credential consumers.
- More decentralized credential issuance and verification workflows.

---

## Project Links

- **Live Application:** https://web3edunear.top
- **GitHub Repository:** https://github.com/Devmaxin/web3edu-near
- **NEAR Testnet Explorer:** https://explorer.testnet.near.org/

---

**Web3Edu — Building a clearer path into Web3, one learner at a time.**