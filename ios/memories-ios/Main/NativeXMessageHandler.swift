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
    let permissionService: PermissionService
    
    init(photoDataSource: PhotoDataSource, getLocalFolders: GetLocalFoldersUseCase, nativeXRequestHandler: NativeXRequestHandler, themeStorage: ThemeStorage, permissionService: PermissionService) {
        self.photoDataSource = photoDataSource
        self.getLocalFolders = getLocalFolders
        self.nativeXRequestHandler = nativeXRequestHandler
        self.themeStorage = themeStorage
        self.permissionService = permissionService
    }
    
    func handleMessage(body: Any) async -> MessageAction {
        guard let scriptMessage = decodeMessage(body: body) else {
            return .returnResult(nil)
        }
        
        switch scriptMessage.method {
        case .isNative: return .returnResult(isNative())
        case .configGetLocalFolders: return .returnResult(configGetLocalFolders())
        case .urlRequest:
            return .returnResult(nativeXRequestHandler.handleUrlRequest(
                urlRequest: scriptMessage.parameter as! UrlRequest
            ))
        case .setThemeColor: setThemeColor(
            scriptMessage.parameter as! ThemeColor
        )
        case .playTouchSound: return .playTouchSound
        case .configHasMediaPermission: return .returnResult(await configHasMediaPermission())
        case .toast: return .toast(toast: scriptMessage.parameter as! Toast)
        default: return .returnResult(nil)
        }
        return .returnResult(nil)
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
    
    func setThemeColor(_ themeColor: ThemeColor) {
        themeStorage.setTheme(color: themeColor.color)
    }
    
    func configHasMediaPermission() async -> Bool {
        return await permissionService.hasMediaPermission()
    }
}

enum MessageAction {
    case returnResult(_ result: Any?)
    case playTouchSound
    case toast(toast: Toast)
}
