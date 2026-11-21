# Developer & Git Guide — TIG Corporate Mileage Tracker

## 1. Branch & Release Workflow (`staging` -> `main`)
- **Active Development (`staging`):** All feature work, fixes, and AI agent sessions (**Antigravity** / **Roo Code**) MUST run on `staging`. Verify `git branch --show-current` is `staging` before editing.
- **Protected Production (`main`):** Never commit directly to `main`.

```bash
cd /media/jeremy/MEDIA/dev/repos/tig-mileage-tracker
git checkout staging && git pull origin staging
git status
git add -A && git commit -m "feat/fix/docs: summary" && git push origin staging
```

## 2. Production Release & SiteGround Deployment
1. Merge `staging` -> `main`, tag, build `/media/jeremy/MEDIA/dev/builds/tig-mileage-tracker.zip`, and return to `staging`:
```bash
git checkout main && git pull origin main && git merge staging --no-edit && git tag -a v3.6.1 -m "Release v3.6.1" && git push origin main && git push origin v3.6.1 && ./build.sh && git checkout staging
```
2. Upload `/media/jeremy/MEDIA/dev/builds/tig-mileage-tracker.zip` in WP Admin (`Plugins > Add New > Upload Plugin > Replace current with uploaded`) and click **Purge SG Cache**.
3. **Pixel 9 App:** `/media/jeremy/MEDIA/dev/repos/TIG-Mileage-Pixel9-App` (`./gradlew assembleDebug`).
4. **Guardrails:** Keep repos in `/media/jeremy/MEDIA/dev/repos/`; never commit `Timeline*.json`, `.csv`, or `.xlsx`; keep `tig-mileage` slug and Return-from-Personal rule.
