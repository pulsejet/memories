const main = document.getElementById("main");
const waiting = document.getElementById("waiting");
const sync = document.getElementById("sync");
const localFolders = document.getElementById("local-folders");

// Waiting page => sync page
window.loggedIn = async () => {
  waiting.classList.add("invisible");
  await new Promise((resolve) => setTimeout(resolve, 700));
  waiting.remove();
  sync.classList.remove("invisible");
};

// Local list of folders
let localList = null;
async function openLocal() {
  // Get the list of local folders for next screen
  localList = JSON.parse(window.nativex?.configGetLocalFolders());

  // Add HTML for folders list
  document.getElementById("folder-list").innerHTML = localList
    .map(
      (folder) => `
            <div class="folder-choose">
                <input type="checkbox" id="folder-${folder.id}" ${folder.enabled ? "checked" : ""}>
                <label for="${folder.id}">${folder.name}</label>
            </div>
        `
    )
    .join("");

  // Show the folders list
  sync.classList.add("invisible");
  await new Promise((resolve) => setTimeout(resolve, 700));
  sync.remove();
  localFolders.classList.remove("invisible");
}

// Open main app
async function start() {
  // Mark all checked as enabled
  if (localList) {
    localList.forEach((f) => (f.enabled = document.getElementById(`folder-${f.id}`).checked));
    window.nativex?.configSetLocalFolders(JSON.stringify(localList));
  }

  // Start the app
  main.classList.add("invisible");
  await new Promise((resolve) => setTimeout(resolve, 700));
  window.nativex?.reload();
}
