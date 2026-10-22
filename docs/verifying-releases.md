# Verifying Releases

[&larr; Back to the README](../README.md)

Streamline overwrites your live application with whatever it downloads, so it is worth being sure
that what it downloaded is what you meant to publish. There are two layers, and they answer
different questions:

| Layer | Question it answers | Trust anchor |
| --- | --- | --- |
| **Checksum** | Did the archive arrive intact? | The release itself |
| **Signature** | Was this release published by *you*? | A public key on your server |

**Both ship turned off**, so that installations upgrading to this version of Streamline keep working
without any change to their release process. While either is off, every update prints a banner
saying so:

```
╔══════════════════════════════════════════════════════════════════════════╗
║                                                                          ║
║  !!  RELEASE VERIFICATION IS DISABLED  !!                                ║
║                                                                          ║
║  This update will install whatever the release publishes. Streamline     ║
║  cannot tell you whether it is genuine.                                  ║
║                                                                          ║
║  Not enforced:                                                           ║
║    - Checksum   STREAMLINE_REQUIRE_RELEASE_CHECKSUM=true                 ║
║    - Signature  STREAMLINE_REQUIRE_RELEASE_SIGNATURE=true                ║
║                                                                          ║
╚══════════════════════════════════════════════════════════════════════════╝

  How to turn these on:
  https://github.com/pixelated-au/streamline/blob/main/docs/verifying-releases.md
```

The banner disappears once both checks are enforced. Turning them on is the two sections below.

## Layer 1: checksums

A checksum proves the archive arrived intact. It does **not** prove the release is genuine, because
it is published by the same release, with the same authority - anyone who can replace `release.zip`
can replace `release.zip.sha256` alongside it. That is what layer 2 is for.

### 1. Publish a checksum with your release

Streamline looks for a file named after the archive plus the algorithm, in the same release. With
the defaults (`release.zip` and `sha256`) that is **`release.zip.sha256`**.

The file may contain either a bare digest:

```
9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08
```

...or the standard `shasum` output format, which is what the usual tools produce:

```
9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08  release.zip
```

Generating it locally:

```bash
# Linux
sha256sum release.zip > release.zip.sha256

# macOS
shasum -a 256 release.zip > release.zip.sha256
```

Then attach **both** files to the GitHub release. (A complete GitHub Actions workflow, covering the
signature as well, is at the end of this section.)

### 2. Enable enforcement on the deployment

A checksum that does not match **always** aborts the update, and the downloaded archive is deleted.
What the setting below controls is what happens when no checksum file can be found at all:

```dotenv
# Abort the update if the release does not publish a checksum file.
STREAMLINE_REQUIRE_RELEASE_CHECKSUM=true
```

It defaults to `false`, so that releases published before you adopted checksums keep working - in
that mode a missing checksum only produces a warning and the update continues. **Set it to `true`
once your release process publishes checksum files**, otherwise an attacker who can remove the
checksum file also removes the check.

To use a different algorithm, set it here - any algorithm your PHP supports (see `hash_algos()`).
The filename Streamline looks for follows from it, so publish the file under the matching name:

```dotenv
STREAMLINE_RELEASE_CHECKSUM_ALGORITHM=sha512   # looks for release.zip.sha512
```

## Layer 2: signatures

A signature moves the trust anchor off the distribution channel and onto a key held by your
deployment. Someone who steals your GitHub token can publish a release, but cannot produce a
signature that satisfies your public key, so the update is refused.

What actually gets signed is the **checksum file**, not the archive. PHP's `openssl_verify()` has no
streaming form and would need the entire archive resident in memory, which is not a safe assumption
on the shared hosting this package targets. Signing the checksum gives the same guarantee through a
short chain - the key vouches for the digest, the digest vouches for the archive - while never
holding more than a few dozen bytes.

### 1. Generate a key pair

```bash
openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out streamline_release.key
openssl pkey -in streamline_release.key -pubout -out streamline_release.pub
```

> **Use an RSA or EC key.** PHP's `openssl_verify()` cannot handle Ed25519 keys at all, however
> valid the signature is. Streamline detects this case and says so rather than reporting tampering,
> but the update will still fail.

`streamline_release.key` is the private key. It must never enter the repository or the release.
`streamline_release.pub` is the public key, and goes onto every deployment.

### 2. Sign the checksum when you publish

```bash
openssl dgst -sha256 -sign streamline_release.key \
    -out release.zip.sha256.sig release.zip.sha256
```

Attach `release.zip.sha256.sig` to the release alongside the other two files. Both raw and
base64-encoded signatures are accepted, so a signature that has been through a text pipeline still
verifies.

### 3. Put the public key on the deployment

Either point at a file:

```dotenv
STREAMLINE_RELEASE_PUBLIC_KEY=/home/you/.config/streamline_release.pub
```

...or paste the PEM inline, with escaped newlines, which is often easier on hosting where you only
have a control panel:

```dotenv
STREAMLINE_RELEASE_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZI...\n-----END PUBLIC KEY-----"
```

The key has to get there through a channel you trust more than GitHub - in practice, you put it
there when you first deploy. That is inherent to the whole idea: if the release could update its own
trust anchor, it would not be an anchor.

### 4. Enable enforcement

```dotenv
STREAMLINE_REQUIRE_RELEASE_SIGNATURE=true
```

An **invalid** signature always aborts the update and deletes the archive, whichever way this is
set. What this controls is whether an *unsigned* release is acceptable. Leave it off while you roll
signing out, then turn it on.

### Rotating the key

If the private key leaks, generate a new pair and get the new public key onto every deployment by
hand. There is no way to automate this, for the same reason the anchor has to be placed
out-of-band - an attacker holding the old key could otherwise sign a release that installs their
own.

## Publishing all three files from GitHub Actions

Store the private key as a repository secret (`STREAMLINE_SIGNING_KEY`), then:

```yaml
      - name: Build the release archive
        run: zip -r release.zip . -x '.git/*' '.github/*' 'node_modules/*'

      - name: Generate the checksum
        run: sha256sum release.zip > release.zip.sha256

      - name: Sign the checksum
        env:
          SIGNING_KEY: ${{ secrets.STREAMLINE_SIGNING_KEY }}
        run: |
          printf '%s' "$SIGNING_KEY" > signing.key
          openssl dgst -sha256 -sign signing.key -out release.zip.sha256.sig release.zip.sha256
          rm -f signing.key

      - name: Publish the release
        uses: softprops/action-gh-release@v2
        with:
          files: |
            release.zip
            release.zip.sha256
            release.zip.sha256.sig
```

## What this does and does not protect you from

| Threat | Checksum | Signature |
| --- | --- | --- |
| A truncated or corrupted download | Yes | Yes |
| A tampered archive, with the checksum left intact | Yes | Yes |
| A compromised GitHub account, where **both** files are replaced | No | **Yes** |
| A compromised CI run with access to the signing secret | No | No |

That last row is the honest limit of signing in CI: an attacker who can read your repository secrets
can sign their own artifact. Signing protects against a stolen token or push access *without* secret
access, which covers most account-takeover scenarios but not all of them. Signing on a machine you
control, or with a hardware token, closes that gap; signing in CI is still a large improvement over
not signing at all.
