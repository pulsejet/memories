//
//  SetCredentialsUseCase.swift
//  memories-ios
//
//  Created by m-oehme on 14.11.23.
//

import Foundation

class SetCredentialsUseCase {
    let secureStorage: SecureCredentialStorage
    let loadCredentialsUseCase: LoadCredentialsUseCase
    
    init(secureStorage: SecureCredentialStorage, refreshCredentialsUseCase: LoadCredentialsUseCase) {
        self.secureStorage = secureStorage
        self.loadCredentialsUseCase = refreshCredentialsUseCase
    }
    
    func invoke(credential: Credential) throws {
        do {
            try secureStorage.saveCredentials(credential: credential)
        } catch StorageError.duplicateKey {
            debugPrint("Duplicate Key. Skipping")
        }
        try loadCredentialsUseCase.invoke()
    }
}
