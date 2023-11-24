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
    let themeStorage: ThemeStorage
    
    init(photoDataSource: PhotoDataSource, getLocalFolders: GetLocalFoldersUseCase, nativeXRequestHandler: NativeXRequestHandler, themeStorage: ThemeStorage) {
        self.photoDataSource = photoDataSource
        self.getLocalFolders = getLocalFolders
        self.nativeXRequestHandler = nativeXRequestHandler
        self.themeStorage = themeStorage
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
        case .setThemeColor: return themeStorage.setTheme(
            color: (scriptMessage.parameter as! ThemeColor).color
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
