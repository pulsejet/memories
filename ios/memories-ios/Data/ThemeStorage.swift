//
//  ThemeStorage.swift
//  memories-ios
//
//  Created by m-oehme on 24.11.23.
//

import Foundation
import Combine

extension UserDefaults {
    @objc var theme: String? {
            get { return string(forKey: Storage.DEFAULTS_KEY + ".theme") }
            set { set(newValue, forKey: Storage.DEFAULTS_KEY + ".theme") }
        }
}

class ThemeStorage {
    private let defaults = UserDefaults.standard
    private var subscriptions = Set<AnyCancellable>()
    
    func getTheme() -> String? {
        return defaults.theme
    }
    
    func setTheme(color: String) {
        defaults.theme = color
    }
    
    func observe(_ observer: @escaping (String?) -> Void) {
        defaults
            .publisher(for: \.theme)
            .handleEvents(receiveOutput: { theme in
                observer(theme)
            })
            .sink { _ in }
            .store(in: &subscriptions)
    }
    
    deinit {
        subscriptions.forEach { c in
            c.cancel()
        }
    }
}
