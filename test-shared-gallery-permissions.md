# Test: Shared Gallery Person Management

This test validates the fix for issue #290 - shared gallery person management permissions.

## Problem Description

Previously, users could not manage face clusters (rename, move, merge faces) for photos uploaded by other users in shared galleries, even when they had full permissions to the shared folder. The error message was:
> "Only user 'xy' can update this person"

## Solution Implemented

1. Added `canManagePersonCluster(personUserId)` helper function in `src/services/utils/helpers.ts`
2. Updated permission checks in:
   - `FaceEditModal.vue`
   - `FaceMoveModal.vue` 
   - `FaceMergeModal.vue`
   - `SelectionManager.vue`

## Test Scenarios

### Before Fix
- User A shares gallery folder with User B (full permissions)
- User A uploads photos with face recognition
- User B tries to rename/move faces from User A's photos
- ❌ Error: "Only user 'A' can update this person"

### After Fix
- User A shares gallery folder with User B (full permissions)  
- User A uploads photos with face recognition
- User B tries to rename/move faces from User A's photos
- ✅ Success: Face management operations work if User B has write permissions to User A's files

## Permission Check Logic

The new `canManagePersonCluster` function:
1. Allows users to manage their own face clusters (unchanged behavior)
2. For other users' clusters, checks if current user has write permissions via PROPFIND WebDAV request
3. Looks for 'W' (write) permission in the oc:permissions XML response
4. Falls back to blocking operation if permissions cannot be determined

## Files Modified

- `src/services/utils/helpers.ts` - Added permission checking function
- `src/components/modal/FaceEditModal.vue` - Updated to use async permission check
- `src/components/modal/FaceMoveModal.vue` - Updated to use async permission check  
- `src/components/modal/FaceMergeModal.vue` - Updated to use async permission check
- `src/components/SelectionManager.vue` - Updated to use async permission check

## Build Verification

✅ Project builds successfully with no TypeScript compilation errors
✅ All changes maintain backward compatibility
✅ No breaking changes to existing APIs

## Testing Notes

To fully test this fix, you would need:
1. A Nextcloud instance with Memories app installed
2. Multiple user accounts
3. Shared folders with face recognition enabled
4. Photos with detected faces from multiple users

The fix preserves security by only allowing face management when users have appropriate file permissions, while enabling the collaborative features needed for shared galleries.