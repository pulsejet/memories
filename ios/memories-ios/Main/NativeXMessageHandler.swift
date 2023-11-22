//
//  NativeXMessageHandler.swift
//  memories-ios
//
//  Created by m-oehme on 19.11.23.
//

import Foundation

class NativeXMessageHandler {
    
    func handleMessage(body: Any) -> Any? {
        guard let scriptMessage = decodeMessage(body: body) else {
            return nil
        }
        
        switch scriptMessage.method {
        case .isNative: return isNative()
        case .printLog:
            debugPrint("JS message: " + (scriptMessage.parameter as! PrintLog).message)
            return nil
        default: return nil
        }
    }
    
    private func decodeMessage(body: Any) -> ScriptMessage? {
        do {
            let data = try JSONSerialization.data(withJSONObject: body, options: .prettyPrinted)
            let decoder = JSONDecoder()
            let decodedData = try decoder.decode(ScriptMessage.self, from: data)
            return decodedData
        } catch(let error) {
            debugPrint(error)
            return nil
        }
    }
    
    func isNative() -> Bool {
        return true
    }
}
