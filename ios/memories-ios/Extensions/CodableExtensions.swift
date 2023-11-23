//
//  CodableExtensions.swift
//  memories-ios
//
//  Created by m-oehme on 23.11.23.
//

import Foundation

extension Encodable {

    /// Converting object to postable dictionary
    func toDictionary(_ encoder: JSONEncoder = JSONEncoder()) throws -> [String: Any] {
        let data = try encoder.encode(self)
        let object = try JSONSerialization.jsonObject(with: data)
        guard let dictonary = object as? [String: Any] else {
            throw EncodingError.encodingFailed
        }
        return dictonary
    }
    
    func toDictionaryArray(_ encoder: JSONEncoder = JSONEncoder()) throws -> [[String: Any]] {
        if self is [Encodable] {
            return try (self as! [Encodable]).map { e in
                try e.toDictionary(encoder)
            }
        } else {
            throw EncodingError.notAnArray
        }
    }
}

enum EncodingError : Error {
    case encodingFailed, notAnArray
}
