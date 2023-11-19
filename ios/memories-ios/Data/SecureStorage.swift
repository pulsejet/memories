//
//  SecureStorage.swift
//  memories-ios
//
//  Created by m-oehme on 13.11.23.
//

import Foundation
import CryptoKit


class SecureStorage {
    private static let key = "gallery.memories"
    private static let URL_KEY = ".url"
    private static let TRUST_ALL_KEY = ".trustAll"
    private static let USERNAME_KEY = ".username"
    
    private static let tokenTag = (key + ".passwords.token").data(using: .utf8)!
    
    
    private let kSecClassValue = NSString(format: kSecClass)
    private let kSecAttrAccountValue = NSString(format: kSecAttrAccount)
    private let kSecValueDataValue = NSString(format: kSecValueData)
    private let kSecClassGenericPasswordValue = NSString(format: kSecClassGenericPassword)
    private let kSecAttrServiceValue = NSString(format: kSecAttrService)
    private let kSecMatchLimitValue = NSString(format: kSecMatchLimit)
    private let kSecReturnDataValue = NSString(format: kSecReturnData)
    private let kSecMatchLimitOneValue = NSString(format: kSecMatchLimitOne)
    
    func saveCredentials(credential: Credential) throws {
        let defaults = UserDefaults.standard
        defaults.set(credential.url, forKey: SecureStorage.key + SecureStorage.URL_KEY)
        defaults.set(credential.trustAll, forKey: SecureStorage.key + SecureStorage.TRUST_ALL_KEY)
        defaults.set(credential.username, forKey: SecureStorage.key + SecureStorage.USERNAME_KEY)
        
        guard let dataFromString = credential.token.data(using: String.Encoding.utf8, allowLossyConversion: false) else {
            throw StorageError.invalidItemFormat
        }
        let keychainQuery: NSMutableDictionary = NSMutableDictionary(
            objects: [kSecClassGenericPasswordValue, credential.url, credential.username, dataFromString],
            forKeys: [kSecClassValue, kSecAttrServiceValue, kSecAttrAccountValue, kSecValueDataValue])
        
        
        let status = SecItemAdd(keychainQuery as CFDictionary, nil)
        guard status == errSecSuccess else {
            switch status {
            case errSecDuplicateItem: throw StorageError.duplicateKey
            default: throw StorageError.unexpectedSaveStatus(
                status: status,
                readable: SecCopyErrorMessageString(status, nil)
            )
            }
        }
    }
    
    func getCredentials() throws -> Credential {
        let defaults = UserDefaults.standard
        guard let url = defaults.string(forKey: SecureStorage.key + SecureStorage.URL_KEY) else {
            throw StorageError.missingCredential(key: SecureStorage.URL_KEY)
        }
        let trustAll = defaults.bool(forKey: SecureStorage.key + SecureStorage.TRUST_ALL_KEY)
        guard let username = defaults.string(forKey: SecureStorage.key + SecureStorage.USERNAME_KEY) else {
            throw StorageError.missingCredential(key: SecureStorage.USERNAME_KEY)
        }
        
        
        let keychainQuery: NSMutableDictionary = NSMutableDictionary(
            objects: [kSecClassGenericPasswordValue, url, username, kCFBooleanTrue, kSecMatchLimitOneValue],
            forKeys: [kSecClassValue, kSecAttrServiceValue, kSecAttrAccountValue, kSecReturnDataValue, kSecMatchLimitValue]
        )
        
        var dataTypeRef: AnyObject?
        let status = SecItemCopyMatching(keychainQuery as CFDictionary, &dataTypeRef)
        guard status == errSecSuccess else {
            switch status {
            case errSecDuplicateItem: throw StorageError.duplicateKey
            default: throw StorageError.unexpectedLoadStatus(
                status: status,
                readable: SecCopyErrorMessageString(status, nil)
            )
            }
        }
        guard let token = dataTypeRef as? Data else {
            throw StorageError.invalidItemFormat
        }
        
        guard let contentsOfKeychain = String(data: token, encoding: String.Encoding.utf8) else {
            throw StorageError.invalidItemFormat
        }
        
        let credentials = Credential(
            url: url,
            trustAll: trustAll,
            username: username,
            token: contentsOfKeychain
        )
        
        return credentials
    }
}

struct Credential {
    
    let url: String
    let trustAll: Bool
    let username: String
    let token: String
}


enum StorageError : Error {
    case unexpectedLoadStatus(status: OSStatus, readable: CFString?)
    case unexpectedSaveStatus(status: OSStatus, readable: CFString?)
    case duplicateKey
    case invalidItemFormat
    case missingCredential(key: String)
}
