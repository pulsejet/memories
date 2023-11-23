//
//  StringExtensions.swift
//  memories-ios
//
//  Created by m-oehme on 13.11.23.
//

import Foundation

extension String {
    func fromBase64() -> String? {
        guard let data = Data(base64Encoded: self) else {
            return nil
        }
        
        return String(data: data, encoding: .utf8)
    }
    
    func toBase64() -> String {
        return Data(self.utf8).base64EncodedString()
    }
    
    func match(regEx: NSRegularExpression) -> Bool {
        let range = NSRange(location: 0, length: self.utf16.count)
        return regEx.firstMatch(in: self, range: range) != nil
    }
}
