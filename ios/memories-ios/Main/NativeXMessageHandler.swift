//
//  NativeXMessageHandler.swift
//  memories-ios
//
//  Created by m-oehme on 19.11.23.
//

import Foundation

class NativeXMessageHandler {
    
    let photoDataSource: PhotoDataSource
    let getLocalFolders: GetLocalFoldersUseCase
    let nativeXRequestHandler: NativeXRequestHandler
    
    init(photoDataSource: PhotoDataSource, getLocalFolders: GetLocalFoldersUseCase, nativeXRequestHandler: NativeXRequestHandler) {
        self.photoDataSource = photoDataSource
        self.getLocalFolders = getLocalFolders
        self.nativeXRequestHandler = nativeXRequestHandler
    }
    
    func handleMessage(body: Any) -> Any? {
        guard let scriptMessage = decodeMessage(body: body) else {
            return nil
        }
        
        switch scriptMessage.method {
        case .isNative: return isNative()
        case .configGetLocalFolders: return configGetLocalFolders()
        case .urlRequest:
            return nativeXRequestHandler.handleUrlRequest(
                urlRequest: scriptMessage.parameter as! UrlRequest
            )
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
    
    func configGetLocalFolders() -> [[String: Any]] {
        do {
            return try getLocalFolders.invoke()
        } catch(let error) {
            debugPrint("Error getting Local Folders", error)
            return [[:]]
        }
    }
}
