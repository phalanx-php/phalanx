# Phalanx

Phalanx is being rebuilt as a v2 greenfield implementation.

The previous implementation was copied to `.v2-reference/source-before-reset/` for local reference during the rebuild. That directory is ignored and is not active source, package code, test code, documentation source, or release material.

Current active source is intentionally minimal until the v2 foundation is rebuilt through the roadmap.

## Bootstrap Contract

Bia can discover the v2 Phalanx bootstrap surface from Composer metadata before autoloading the package:

```json
{
    "extra": {
        "phalanx": {
            "bootstrap": {
                "contract": "2.0",
                "entrypoint": "Phalanx\\Phalanx",
                "package": "phalanx-php/phalanx",
                "version": "2.0-dev"
            }
        }
    }
}
```

After Composer loads the package, the same contract is available through `Phalanx\Phalanx::bootstrapContract()`.
