package config

import (
	"encoding/json"
	"testing"

	"github.com/stretchr/testify/require"
)

func TestTCfgValidate(t *testing.T) {
	cases := []struct {
		name   string
		mutate func(*TCfg)
		ok     bool
	}{
		{"valid", func(*TCfg) {}, true},
		{"zero chunk", func(c *TCfg) { c.ChunkSize = 0 }, false},
		{"vaapi and nvenc", func(c *TCfg) { c.VAAPI, c.NVENC, c.NVENCScale = true, true, "cuda" }, false},
		{"bad nvenc scale", func(c *TCfg) { c.NVENCScale = "vulkan" }, false},
		{"nvenc without scale", func(c *TCfg) { c.NVENC = true }, false},
		{"nvenc cuda", func(c *TCfg) { c.NVENC, c.NVENCScale = true, "cuda" }, true},
		{"nvenc npp", func(c *TCfg) { c.NVENC, c.NVENCScale = true, "npp" }, true},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			c := &TCfg{ChunkSize: 3}
			tc.mutate(c)
			if tc.ok {
				require.NoError(t, c.Validate())
			} else {
				require.Error(t, c.Validate())
			}
		})
	}
}

func TestTCfgUnmarshal(t *testing.T) {
	var c TCfg
	require.NoError(t, json.Unmarshal([]byte(
		`{"chunkSize":3,"qf":24,"vaapiDevice":"/dev/dri/renderD129"}`,
	), &c))
	require.Equal(t, 3, c.ChunkSize)
	require.Equal(t, 24, c.QF)
	require.Equal(t, "/dev/dri/renderD129", c.VAAPIDevice)
	require.NoError(t, c.Validate())
}
