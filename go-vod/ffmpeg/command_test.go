package ffmpeg

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/require"
)

// processGone reports a pid that no longer runs (exited or a zombie).
func processGone(pid int) bool {
	stat, err := os.ReadFile(fmt.Sprintf("/proc/%d/stat", pid))
	return err != nil || strings.Contains(string(stat), ") Z ")
}

func TestCommandDiesWithParent(t *testing.T) {
	if os.Getenv("GO_VOD_COMMAND_HELPER") != "" {
		cmd := Command(context.Background(), "sleep", "30")
		require.NoError(t, cmd.Start())
		fmt.Println(cmd.Process.Pid)
		os.Exit(0)
	}

	helper := exec.Command(os.Args[0], "-test.run=^TestCommandDiesWithParent$")
	helper.Env = append(os.Environ(), "GO_VOD_COMMAND_HELPER=1")
	out, err := helper.Output()
	require.NoError(t, err)
	pid, err := strconv.Atoi(strings.TrimSpace(string(out)))
	require.NoError(t, err)

	require.Eventually(t, func() bool { return processGone(pid) }, 5*time.Second, 20*time.Millisecond)
}
