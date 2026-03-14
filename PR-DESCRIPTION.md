# Fix shared gallery person management permissions

## Problem
Users cannot manage face clusters (rename, move, merge faces) for photos uploaded by other users in shared galleries, even when they have full permissions to the shared folder.

**Error message:** "Only user '{user}' can update this person"

This prevents collaborative face organization in shared family or group galleries.

## Root Cause
The frontend components had strict ownership checks (`this.user !== utils.uid`) that blocked all face management operations on clusters belonging to other users, regardless of shared folder permissions.

## Solution
- Added `canManagePersonCluster(personUserId)` helper function that checks WebDAV permissions
- Updated all face management modals to use permission-based access control instead of strict ownership
- Preserves security by only allowing operations when users have write permissions to the relevant files

## Changes Made
1. **New Permission Helper** (`src/services/utils/helpers.ts`):
   - `canManagePersonCluster()` function using PROPFIND WebDAV requests
   - Checks for write permissions ('W' flag) in oc:permissions
   - Falls back to current user only if permission check fails

2. **Updated Components**:
   - `FaceEditModal.vue` - Person renaming
   - `FaceMoveModal.vue` - Moving faces between persons  
   - `FaceMergeModal.vue` - Merging face clusters
   - `SelectionManager.vue` - Removing faces from persons

## Testing
- ✅ Project builds successfully
- ✅ TypeScript compilation passes
- ✅ Backward compatibility maintained
- ✅ No breaking API changes

## Security Considerations
- Permission checks are done on each operation
- Falls back to restrictive behavior if permissions cannot be determined
- Uses existing Nextcloud WebDAV permission system
- No new security vulnerabilities introduced

## Fixes
Closes #290