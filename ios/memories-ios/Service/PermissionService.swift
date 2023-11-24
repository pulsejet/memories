//
//  PermissionService.swift
//  memories-ios
//
//  Created by m-oehme on 24.11.23.
//

import Foundation
import Photos

class PermissionService {
    
    func hasMediaPermission() async -> Bool {
        let status = PHPhotoLibrary.authorizationStatus(for: .readWrite)
        
        if (status == .authorized) {
            // Access has been granted.
            return true
        } else if status == .notDetermined {
            let newStatus = await PHPhotoLibrary.requestAuthorization(for: .readWrite)
            if (newStatus == PHAuthorizationStatus.authorized) {
                return true
            } else {
                return false
            }
        } else {
            return false
        }
    }
}
